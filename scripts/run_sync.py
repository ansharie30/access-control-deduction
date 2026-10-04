import os
import time
import requests
from openpyxl import load_workbook
from datetime import datetime

EXCEL_FILE = "API-DEDUCTION-INTEGRATION-TEMPLATE-v2.xlsm"
SHEET_NAME = "API_Deductions"

print("Starting auto-sync daemon (Interval: 5 seconds)... Press Ctrl+C to stop.\n")

while True:
    try:
        if not os.path.exists(EXCEL_FILE):
            print(f"Error: {EXCEL_FILE} not found. Retrying in 5s...")
            time.sleep(5)
            continue

        wb = load_workbook(EXCEL_FILE, keep_vba=True)
        ws = wb[SHEET_NAME]

        # Map headers on row 5 dynamically
        headers = {
            str(ws.cell(row=5, column=c).value).strip().lower().replace("_", "").replace(" ", ""): c
            for c in range(1, ws.max_column + 1)
            if ws.cell(row=5, column=c).value
        }

        updated_count = 0
        today_date = datetime.now().strftime("%Y-%m-%d")

        for r in range(6, ws.max_row + 1):
            emp_ref = ws.cell(row=r, column=headers.get("employeeref", 1)).value or ws.cell(row=r, column=headers.get("responseemployeeref", 3)).value
            if not emp_ref:
                continue

            pin = "".join(filter(str.isdigit, str(emp_ref)))
            if not pin:
                continue

            clean_emp_ref = f"EMP-{int(pin):05d}"
            url = f"http://127.0.0.1:8000/api/attendance/calculate?employee_pin={pin}&date={today_date}"

            try:
                res = requests.get(url, headers={"Accept": "application/json"}, timeout=5)

                if res.status_code == 200:
                    data = res.json()
                    deduction = float(data.get("salary_deduction", 0.0))
                    day_type = data.get("day_type", "calculated")
                    call_status = "success"
                    err_msg = ""
                else:
                    deduction = 0.0
                    day_type = "no_record"
                    call_status = "error"
                    err_msg = f"HTTP {res.status_code}: Not Found"

                # Update worksheet response fields (Request column 'employeeref' is preserved)
                if "responseemployeeref" in headers:
                    ws.cell(row=r, column=headers["responseemployeeref"]).value = clean_emp_ref
                if "deductionamount" in headers:
                    ws.cell(row=r, column=headers["deductionamount"]).value = deduction
                if "status" in headers:
                    ws.cell(row=r, column=headers["status"]).value = day_type
                if "apicallstatus" in headers:
                    ws.cell(row=r, column=headers["apicallstatus"]).value = call_status
                if "apierrormessage" in headers:
                    ws.cell(row=r, column=headers["apierrormessage"]).value = err_msg

                updated_count += 1

            except Exception as e:
                if "apicallstatus" in headers:
                    ws.cell(row=r, column=headers["apicallstatus"]).value = "error"
                if "apierrormessage" in headers:
                    ws.cell(row=r, column=headers["apierrormessage"]).value = str(e)

        wb.save(EXCEL_FILE)
        print(f"[{time.strftime('%H:%M:%S')}] Synced {updated_count} rows. Sleeping 5s...")

    except PermissionError:
        print(f"[{time.strftime('%H:%M:%S')}] File locked. Retrying in 5s...")
    except Exception as e:
        print(f"[{time.strftime('%H:%M:%S')}] Unexpected error: {e}")

    time.sleep(5)