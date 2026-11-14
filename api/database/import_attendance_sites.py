"""Backfill attendances.site_id from the legacy export (attendance.site -> sites.legacy_id).
Usage: python3 -I database/import_attendance_sites.py ~/selgem-srs/data/attendances_full.json"""
import json, subprocess, sys, io
src = sys.argv[1]
rows = json.load(open(src))
pairs = [(r.get('id') or r.get('_id'), r['site'].get('_id') or r['site'].get('id')) for r in rows if isinstance(r.get('site'), dict)]
buf = io.StringIO(''.join(f"{a}\t{s}\n" for a, s in pairs if a and s))
sql = """
CREATE TEMP TABLE att_site(att_legacy text, site_legacy text);
COPY att_site FROM STDIN;
UPDATE attendances a SET site_id = s.id FROM att_site t JOIN sites s ON s.legacy_id = t.site_legacy WHERE a.legacy_id = t.att_legacy;
SELECT count(*) FROM attendances WHERE site_id IS NOT NULL;
"""
# COPY data must follow the COPY command on stdin
script = sql.split('COPY att_site FROM STDIN;')
p = subprocess.run(['psql', '-h', '127.0.0.1', '-d', 'selgem', '-v', 'ON_ERROR_STOP=1', '-tA'],
                   input=script[0] + 'COPY att_site FROM STDIN;\n' + buf.getvalue() + '\\.\n' + script[1], text=True, capture_output=True)
print(p.stdout.strip(), p.stderr.strip())
