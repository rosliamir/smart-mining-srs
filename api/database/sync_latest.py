#!/usr/bin/env python3
"""Pull the latest data from the live Strapi and re-import into Postgres.
Currently syncs the volatile collections: employees (staff fields) + attendances.
Run: python3 sync_latest.py   (prints a JSON summary on the last line)."""
import json, csv, subprocess, os, tempfile, urllib.request, re, sys

STRAPI = "https://selgem-api.inference.asia"
DB = "selgem"
TMP = tempfile.mkdtemp()
_TIME = re.compile(r'^\d{1,2}:\d{2}(:\d{2})?$')

def psql(sql): return subprocess.run(["psql","-d",DB,"-tAc",sql],capture_output=True,text=True).stdout
def psqlc(sql): return subprocess.run(["psql","-d",DB,"-c",sql],capture_output=True,text=True)

def http_json(path, token=None, data=None):
    req = urllib.request.Request(STRAPI+path, method="POST" if data else "GET")
    req.add_header("Content-Type","application/json")
    if token: req.add_header("Authorization","Bearer "+token)
    body = json.dumps(data).encode() if data else None
    with urllib.request.urlopen(req, data=body, timeout=180) as r:
        return json.loads(r.read().decode())

def main():
    jwt = http_json("/auth/local", data={"identifier":os.environ["LEGACY_USER"],"password":os.environ["LEGACY_PASSWORD"]})["jwt"]
    employees = http_json("/employees?_limit=-1", token=jwt)
    attend = http_json("/attendances?_limit=-1&_sort=date:ASC", token=jwt)

    # employee legacy->id map
    EMP = {}
    for l in psql("SELECT legacy_id,id FROM employees WHERE legacy_id IS NOT NULL").splitlines():
        if "|" in l: k,v = l.split("|",1); EMP[k]=int(v)

    # 1) refresh employee staff fields
    p = os.path.join(TMP,"emp.csv")
    with open(p,"w",newline="") as f:
        w=csv.writer(f)
        for e in employees:
            w.writerow([e.get("_id"), e.get("employee_id") or "",
                        e.get("workdays") if isinstance(e.get("workdays"),int) else "",
                        e.get("shift") or "", e.get("hqRegion") or ""])
    psqlc("DROP TABLE IF EXISTS _emp_stage; CREATE TABLE _emp_stage(legacy_id text, employee_id text, workdays int, shift text, hq_region text);")
    subprocess.run(["psql","-d",DB,"-c",f"\\copy _emp_stage FROM '{p}' WITH (FORMAT csv, NULL '')"],capture_output=True,text=True)
    psqlc("UPDATE employees e SET employee_id=s.employee_id, workdays=s.workdays, shift=s.shift, hq_region=s.hq_region FROM _emp_stage s WHERE e.legacy_id=s.legacy_id;")
    psqlc("DROP TABLE _emp_stage;")

    # 2) re-import attendances
    def lid(r): return (r.get("_id") or r.get("id")) if isinstance(r,dict) else r
    def tm(v): return v if isinstance(v,str) and _TIME.match(v) else ""
    pa = os.path.join(TMP,"att.csv"); n=0
    with open(pa,"w",newline="") as f:
        w=csv.writer(f)
        for r in attend:
            emp=EMP.get(lid(r.get("employee")))
            if not emp: continue
            c=(r.get("createdAt") or "2026-01-01T00:00:00")[:19].replace("T"," ")
            w.writerow([emp,(r.get("date") or "")[:10], r.get("status") or "",
                        tm(r.get("timeclock_in")), tm(r.get("timeclock_out")),
                        "", r.get("_id"), c, c]); n+=1
    psqlc("TRUNCATE attendances RESTART IDENTITY;")
    subprocess.run(["psql","-d",DB,"-c",f"\\copy attendances (employee_id,date,status,timeclock_in,timeclock_out,lat,legacy_id,created_at,updated_at) FROM '{pa}' WITH (FORMAT csv, NULL '')"],capture_output=True,text=True)

    # 3) full sales/daily-reports sync (upsert by legacy_id — no deletes, handles past & future)
    def d10(v): return v[:10] if isinstance(v,str) and v else None
    def num(v):
        if v in (None,""): return None
        try: return float(str(v).replace(",","").split()[0])
        except: return None
    WR = {}
    for l in psql("SELECT legacy_id,id FROM weekly_reports WHERE legacy_id IS NOT NULL").splitlines():
        if "|" in l: k,v=l.split("|",1); WR[k]=int(v)
    daily = http_json("/daily-reports?_limit=-1", token=jwt)
    sales = http_json("/daily-sales-records?_limit=-1", token=jwt)

    # upsert daily_reports (date + weekly_report_id)
    pd = os.path.join(TMP,"dr.csv"); dn=0
    with open(pd,"w",newline="") as f:
        w=csv.writer(f)
        for r in daily:
            wr=WR.get(lid(r.get("weekly_report")))
            if not wr or not r.get("date"): continue
            w.writerow([r.get("_id"), wr, d10(r.get("date"))]); dn+=1
    psqlc("DROP TABLE IF EXISTS _dr_stage; CREATE TABLE _dr_stage(legacy_id text, weekly_report_id bigint, date date);")
    subprocess.run(["psql","-d",DB,"-c",f"\\copy _dr_stage FROM '{pd}' WITH (FORMAT csv, NULL '')"],capture_output=True,text=True)
    psqlc("""INSERT INTO daily_reports (legacy_id, weekly_report_id, date, created_at, updated_at)
             SELECT legacy_id, weekly_report_id, date, now(), now() FROM _dr_stage
             ON CONFLICT (legacy_id) DO UPDATE SET weekly_report_id=excluded.weekly_report_id, date=excluded.date;""")
    psqlc("DROP TABLE _dr_stage;")

    DR = {}
    for l in psql("SELECT legacy_id,id FROM daily_reports WHERE legacy_id IS NOT NULL").splitlines():
        if "|" in l: k,v=l.split("|",1); DR[k]=int(v)

    # upsert daily_sales_records (jualan etc.)
    ps = os.path.join(TMP,"dsr.csv"); sn=0
    with open(ps,"w",newline="") as f:
        w=csv.writer(f)
        for r in sales:
            dr=DR.get(lid(r.get("daily_report")))
            if not dr: continue
            w.writerow([r.get("_id"), dr, num(r.get("jualan")) if num(r.get("jualan")) is not None else "",
                        num(r.get("jualan_terkumpul")) if num(r.get("jualan_terkumpul")) is not None else ""]); sn+=1
    psqlc("DROP TABLE IF EXISTS _dsr_stage; CREATE TABLE _dsr_stage(legacy_id text, daily_report_id bigint, jualan numeric, jualan_terkumpul numeric);")
    subprocess.run(["psql","-d",DB,"-c",f"\\copy _dsr_stage FROM '{ps}' WITH (FORMAT csv, NULL '')"],capture_output=True,text=True)
    psqlc("""INSERT INTO daily_sales_records (legacy_id, daily_report_id, jualan, jualan_terkumpul, created_at, updated_at)
             SELECT legacy_id, daily_report_id, jualan, jualan_terkumpul, now(), now() FROM _dsr_stage
             ON CONFLICT (legacy_id) DO UPDATE SET daily_report_id=excluded.daily_report_id, jualan=excluded.jualan, jualan_terkumpul=excluded.jualan_terkumpul;""")
    psqlc("DROP TABLE _dsr_stage;")

    dates = psql("SELECT count(distinct date) FROM attendances").strip()
    latest = psql("SELECT max(date) FROM attendances").strip()
    sales_range = psql("SELECT min(date)||' .. '||max(date) FROM daily_reports").strip()
    print(json.dumps({"ok":True,"employees":len(employees),"attendances":n,"dates":int(dates or 0),
                      "latest_date":latest,"daily_reports":dn,"sales_records":sn,"sales_date_range":sales_range}))

if __name__ == "__main__":
    try: main()
    except Exception as e:
        print(json.dumps({"ok":False,"error":str(e)[:200]})); sys.exit(1)
