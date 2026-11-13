#!/usr/bin/env python3
"""Stream the full Strapi export into Postgres via CSV + COPY, resolving legacy FKs."""
import ijson, csv, subprocess, os, sys, tempfile, json

DATA = os.path.expanduser("~/selgem-srs/data")
DB = "selgem"
TMP = tempfile.mkdtemp(prefix="smimport_")

def psql(sql):
    return subprocess.run(["psql","-d",DB,"-tAc",sql], capture_output=True, text=True).stdout

def load_map(table):
    out = psql(f"SELECT legacy_id,id FROM {table} WHERE legacy_id IS NOT NULL")
    m = {}
    for line in out.splitlines():
        if "|" in line:
            k,v = line.split("|",1); m[k]=int(v)
    return m

def lid(ref):
    if ref is None: return None
    if isinstance(ref, dict): return ref.get("_id") or ref.get("id")
    return ref

def ts(r):
    return r.get("createdAt") or "2026-01-01T00:00:00Z", r.get("updatedAt") or "2026-01-01T00:00:00Z"

def d10(r, key):
    """Date-only (YYYY-MM-DD) for Postgres date columns."""
    v = r.get(key)
    if not v or not isinstance(v, str): return ""
    return v[:10]

def copy_csv(table, cols, rows_iter):
    path = os.path.join(TMP, table+".csv")
    n = 0
    with open(path,"w",newline="") as f:
        w = csv.writer(f)
        for row in rows_iter:
            w.writerow(row); n += 1
    if n == 0:
        print(f"  {table:28} 0 rows (skip)"); return
    collist = ",".join(cols)
    cmd = f"\\copy {table} ({collist}) FROM '{path}' WITH (FORMAT csv, NULL '')"
    r = subprocess.run(["psql","-d",DB,"-c",cmd], capture_output=True, text=True)
    status = r.stdout.strip() or r.stderr.strip()[:120]
    print(f"  {table:28} {n:>7} rows  {status}")

def stream(file):
    with open(os.path.join(DATA, file+".json"),"rb") as f:
        for rec in ijson.items(f, "item"):
            yield rec

def val(r,k):
    v = r.get(k)
    if v is None: return ""
    if isinstance(v,(dict,list)): return json.dumps(v, ensure_ascii=False, default=str)
    if isinstance(v,bool): return "t" if v else "f"
    return v

CHILD_TABLES = ["equipment_daily_counts","weekly_equipment_entries","report_activities",
    "non_compliances","weekly_notes","report_attendances","weekly_assignments","avr_readings",
    "daily_sales_records","weekly_sales_products","daily_environment_statuses","daily_system_statuses",
    "daily_reports","site_polygons","site_standoffs","site_galleries","point_clouds","tiles",
    "landowner_monthly_reports","complaint_files"]
print("truncating child tables for clean re-run…")
psql("TRUNCATE " + ", ".join(CHILD_TABLES) + " RESTART IDENTITY CASCADE;")

print("building parent maps…")
WR  = load_map("weekly_reports")
EMP = load_map("employees")
USR = load_map("users")
SITE= load_map("sites")
EIT = load_map("equipment_item_types")

# 1. daily_reports
MP=['mekanik','operator_longarm','operator_palong','pekerja_am','pemandu_excavator','pemandu_lori','pemandu_motor_grader','pemandu_rb','pemandu_shovel','pemandu_tractor','pemandu_water_tanker']
def daily_rows():
    for r in stream("daily-reports"):
        wr = WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u = ts(r)
        counts=[r.get(f"{m}_count") or 0 for m in MP]
        yield [wr, d10(r,"date"), "t" if r.get("is_public_holiday") else "f",
               r.get("public_holiday_name","") or "", val(r,"weather_slots"), *counts,
               r.get("_id"), c, u]
copy_csv("daily_reports",
    ["weekly_report_id","date","is_public_holiday","public_holiday_name","weather_slots",
     *[f"{m}_count" for m in MP],"legacy_id","created_at","updated_at"], daily_rows())

DR = load_map("daily_reports")

# 2. daily_system_statuses
def dss_rows():
    for r in stream("daily-system-statuses"):
        dr = DR.get(lid(r.get("daily_report")))
        if not dr: continue
        c,u=ts(r)
        yield [dr, val(r,"cctv_dalam_kabin_status"), val(r,"cctv_luar_status"),
               val(r,"cty_gemma_status"), val(r,"e_docket_status"), r.get("_id"), c, u]
copy_csv("daily_system_statuses",
    ["daily_report_id","cctv_dalam_kabin_status","cctv_luar_status","cty_gemma_status","e_docket_status","legacy_id","created_at","updated_at"], dss_rows())

# 3. daily_environment_statuses
DES_F=['effluent_pagi','effluent_tghari','effluent_petang','kolam1','kolam2','kolam3','spillway','spilltray','papan_tanda','tanda_sempadan','laluan_keluar_masuk','maintenance']
DES_IMG=['spilltray_images','papan_tanda_images','tanda_sempadan_images','laluan_keluar_masuk_images']
def des_rows():
    for r in stream("daily-environment-statuses"):
        dr = DR.get(lid(r.get("daily_report")))
        if not dr: continue
        c,u=ts(r)
        yield [dr, *[val(r,f) for f in DES_F], *[val(r,f) for f in DES_IMG], r.get("_id"), c, u]
copy_csv("daily_environment_statuses",
    ["daily_report_id",*DES_F,*DES_IMG,"legacy_id","created_at","updated_at"], des_rows())

# 4. weekly_sales_products
def wsp_rows():
    for r in stream("weekly-sales-products"):
        wr=WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u=ts(r)
        yield [wr, val(r,"product"), val(r,"permit_code"), d10(r,"permit_start_date"), d10(r,"permit_end_date"), r.get("_id"), c, u]
copy_csv("weekly_sales_products",
    ["weekly_report_id","product","permit_code","permit_start_date","permit_end_date","legacy_id","created_at","updated_at"], wsp_rows())
WSP = load_map("weekly_sales_products")

# 5. daily_sales_records
DSR_STR=['doket_kssb_mula','doket_kssb_akhir','doket_ptd_mula','doket_ptd_akhir']
DSR_INT=['doket_kssb_baki','doket_ptd_baki','lori_trip']
DSR_DEC=['stok_mula','baki_stockpile','baki_topup','jualan','jualan_terkumpul','pengeluaran_cuci']
def num(v):
    if v in (None,""): return ""
    try: return float(str(v).replace(",","").split()[0])
    except: return ""
def dsr_rows():
    for r in stream("daily-sales-records"):
        dr=DR.get(lid(r.get("daily_report")))
        if not dr: continue
        c,u=ts(r)
        yield [dr, WSP.get(lid(r.get("weekly_sales_product"))) or "",
               *[val(r,f) for f in DSR_STR], *[r.get(f) if isinstance(r.get(f),int) else "" for f in DSR_INT],
               *[num(r.get(f)) for f in DSR_DEC], r.get("_id"), c, u]
copy_csv("daily_sales_records",
    ["daily_report_id","weekly_sales_product_id",*DSR_STR,*DSR_INT,*DSR_DEC,"legacy_id","created_at","updated_at"], dsr_rows())

# 6. avr_readings
def avr_rows():
    for r in stream("avr-readings"):
        dr=DR.get(lid(r.get("daily_report")))
        if not dr: continue
        c,u=ts(r)
        yield [dr, val(r,"weighbridge"), val(r,"shift"), num(r.get("input")), num(r.get("output")), r.get("_id"), c, u]
copy_csv("avr_readings", ["daily_report_id","weighbridge","shift","input","output","legacy_id","created_at","updated_at"], avr_rows())

# 7. weekly_assignments
def wa_rows():
    for r in stream("weekly-assignments"):
        wr=WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u=ts(r)
        yield [wr, EMP.get(lid(r.get("employee"))) or "", val(r,"shift"), val(r,"note"), r.get("_id"), c, u]
copy_csv("weekly_assignments", ["weekly_report_id","employee_id","shift","note","legacy_id","created_at","updated_at"], wa_rows())
WA = load_map("weekly_assignments")

# 8. report_attendances
def ra_rows():
    for r in stream("report-attendances"):
        dr=DR.get(lid(r.get("daily_report")))
        c,u=ts(r)
        yield [dr or "", WA.get(lid(r.get("weekly_assignment"))) or "", EMP.get(lid(r.get("employee"))) or "",
               d10(r,"date"), val(r,"status"), val(r,"note"), r.get("_id"), c, u]
copy_csv("report_attendances", ["daily_report_id","weekly_assignment_id","employee_id","date","status","note","legacy_id","created_at","updated_at"], ra_rows())

# 9. weekly_notes
def wn_rows():
    for r in stream("weekly-notes"):
        wr=WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u=ts(r)
        yield [wr, d10(r,"tarikh"), val(r,"tindakan"), val(r,"ulasan"), d10(r,"deadline"), r.get("_id"), c, u]
copy_csv("weekly_notes", ["weekly_report_id","tarikh","tindakan","ulasan","deadline","legacy_id","created_at","updated_at"], wn_rows())

# 10. non_compliances
def nc_rows():
    for r in stream("non-compliances"):
        wr=WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u=ts(r)
        yield [wr, val(r,"jabatan"), d10(r,"tarikh"), val(r,"ulasan"), r.get("_id"), c, u]
copy_csv("non_compliances", ["weekly_report_id","jabatan","tarikh","ulasan","legacy_id","created_at","updated_at"], nc_rows())

# 11. report_activities
def rac_rows():
    for r in stream("report-activities"):
        wr=WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u=ts(r)
        yield [wr, val(r,"from_status"), val(r,"to_status"), USR.get(lid(r.get("action_by"))) or "",
               val(r,"action_by_name"), val(r,"action_by_role"), r.get("_id"), c, u]
copy_csv("report_activities", ["weekly_report_id","from_status","to_status","action_by","action_by_name","action_by_role","legacy_id","created_at","updated_at"], rac_rows())

# 12. weekly_equipment_entries
def wee_rows():
    for r in stream("weekly-equipment-entries"):
        wr=WR.get(lid(r.get("weekly_report")))
        if not wr: continue
        c,u=ts(r)
        yield [wr, EIT.get(lid(r.get("item_type"))) or "", val(r,"brand"), val(r,"owner"),
               val(r,"specification"), val(r,"note"), val(r,"images"), r.get("_id"), c, u]
copy_csv("weekly_equipment_entries", ["weekly_report_id","equipment_item_type_id","brand","owner","specification","note","images","legacy_id","created_at","updated_at"], wee_rows())
WEE = load_map("weekly_equipment_entries")

# 13. equipment_daily_counts (largest ~127k)
def edc_rows():
    for r in stream("equipment-daily-counts"):
        e=WEE.get(lid(r.get("weekly_equipment_entry")))
        if not e: continue
        c,u=ts(r)
        yield [e, DR.get(lid(r.get("daily_report"))) or "", r.get("count") or 0, r.get("_id"), c, u]
copy_csv("equipment_daily_counts", ["weekly_equipment_entry_id","daily_report_id","count","legacy_id","created_at","updated_at"], edc_rows())

# 14. site assets
def poly_rows():
    for r in stream("site-polygons"):
        s=SITE.get(lid(r.get("site")))
        if not s: continue
        c,u=ts(r)
        yield [s, val(r,"name"), d10(r,"date"), val(r,"features"), r.get("_id"), c, u]
copy_csv("site_polygons", ["site_id","name","date","features","legacy_id","created_at","updated_at"], poly_rows())

def stand_rows():
    for r in stream("site-standoffs"):
        s=SITE.get(lid(r.get("site")))
        if not s: continue
        c,u=ts(r)
        yield [s, val(r,"name"), num(r.get("lat")), num(r.get("lng")), num(r.get("bearing")), val(r,"media"), r.get("_id"), c, u]
copy_csv("site_standoffs", ["site_id","name","lat","lng","bearing","media","legacy_id","created_at","updated_at"], stand_rows())

def gal_rows():
    for r in stream("site-galleries"):
        s=SITE.get(lid(r.get("site")))
        if not s: continue
        c,u=ts(r)
        yield [s, val(r,"name"), val(r,"media"), r.get("_id"), c, u]
copy_csv("site_galleries", ["site_id","name","media","legacy_id","created_at","updated_at"], gal_rows())

def pc_rows():
    for r in stream("point-clouds"):
        s=SITE.get(lid(r.get("site")))
        if not s: continue
        c,u=ts(r)
        yield [s, val(r,"url"), r.get("_id"), c, u]
copy_csv("point_clouds", ["site_id","url","legacy_id","created_at","updated_at"], pc_rows())

def tile_rows():
    for r in stream("tiles"):
        s=SITE.get(lid(r.get("site")))
        if not s: continue
        c,u=ts(r)
        yield [s, val(r,"name"), d10(r,"date"), val(r,"url"), r.get("_id"), c, u]
copy_csv("tiles", ["site_id","name","date","url","legacy_id","created_at","updated_at"], tile_rows())

def lo_rows():
    for r in stream("landowner-monthly-reports"):
        c,u=ts(r)
        yield [SITE.get(lid(r.get("site"))) or "", d10(r,"date"), val(r,"status"),
               val(r,"submitted_by"), val(r,"reviewed_by"), val(r,"approved_by"), r.get("_id"), c, u]
copy_csv("landowner_monthly_reports", ["site_id","date","status","submitted_by","reviewed_by","approved_by","legacy_id","created_at","updated_at"], lo_rows())

def cf_rows():
    for r in stream("complaint-files"):
        c,u=ts(r)
        yield [SITE.get(lid(r.get("site"))) or "", val(r,"name"), val(r,"type"), val(r,"files"),
               USR.get(lid(r.get("upload_by"))) or "", r.get("_id"), c, u]
copy_csv("complaint_files", ["site_id","name","type","files","upload_by","legacy_id","created_at","updated_at"], cf_rows())

print("DONE.")
