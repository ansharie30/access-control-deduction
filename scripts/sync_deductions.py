import os
import requests
from openpyxl import load_workbook
import time
from dotenv import load_dotenv

load_dotenv()

EXCEL_FILE = os.getenv("EXCEL_FILE", "API-DEDUCTION-INTEGRATION-TEMPLATE-v2.xlsx")
SHEET_NAME = os.getenv("SHEET_NAME", "API_Deductions")
API_BASE_URL = os.getenv("API_BASE_URL", "http://127.0.0.1:8000/api")

def normalize_header(header_text):
    if not header_text:
        return ""
    return str(header_text).strip().lower().replace("_", "").replace(" ", "")

def run_deduction_sync():
    if not os.path.exists(EXCEL_FILE):
        raise FileNotFoundError(f"Excel file '{EXCEL_FILE}' not found in {os.getcwd()}")

    # Use data_only=False so we can modify and save without destroying formatting
    wb = load_workbook(EXCEL_FILE)
    ws = wb[SHEET_NAME]

    header_row = 5
    col_map = {}

    # Map column headers from Row 5
    for col in range(1, ws.max_column + 1):
        raw_val = ws.cell(row=header_row, column=col).value
        if raw_val:
            normalized = normalize_header(raw_val)
            col_map[normalized] = col

    print("[1/2] Querying API & syncing deductions...")
    start_row = 6
    processed_count = 0
    headers = {"Accept": "application/json"}

    for r in range(start_row, ws.max_row + 1):
        emp_ref_cell = ws.cell(row=r, column=col_map.get("employeeref", 1)).value
        period_cell = ws.cell(row=r, column=col_map.get("period", 2)).value

        if not emp_ref_cell or not period_cell:
            continue

        processed_count += 1
        emp_ref = str(emp_ref_cell).strip()
        period = str(period_cell).strip()
        print(f"  -> Processing Row {r}: {emp_ref} | Period: {period}")

        # Extract numeric PIN (e.g., 'EMP-API-19' -> '19', 'EMP-00001' -> '1')
        pin_val = str(emp_ref).replace("EMP-API-", "").replace("EMP-", "").strip()

        try:
            resp = requests.get(
                f"{API_BASE_URL}/attendance/calculate",
                headers=headers,
                params={"employee_pin": pin_val},
                timeout=10
            )

            if resp.status_code == 200:
                data = resp.json()

                # 1. response_employee_ref
                if "responseemployeeref" in col_map:
                    ws.cell(row=r, column=col_map["responseemployeeref"]).value = data.get("employee_ref", emp_ref)

                # 2. response_period_code
                if "responseperiodcode" in col_map:
                    ws.cell(row=r, column=col_map["responseperiodcode"]).value = period

                # 3. status
                if "status" in col_map:
                    ws.cell(row=r, column=col_map["status"]).value = data.get("day_type", "calculated")

                # 4. as_of
                if "asof" in col_map:
                    ws.cell(row=r, column=col_map["asof"]).value = data.get("as_of", "2026-05-01")

                # 5. deduction_amount
                if "deductionamount" in col_map:
                    ws.cell(row=r, column=col_map["deductionamount"]).value = float(data.get("salary_deduction", 0.0))

                # 6. reason
                if "reason" in col_map:
                    ws.cell(row=r, column=col_map["reason"]).value = str(data.get("rule_trace", ""))

                # 7. api_call_status & error
                if "apicallstatus" in col_map:
                    ws.cell(row=r, column=col_map["apicallstatus"]).value = "success"
                if "apierrormessage" in col_map:
                    ws.cell(row=r, column=col_map["apierrormessage"]).value = ""

            else:
                err_msg = f"HTTP {resp.status_code}: {resp.text}"
                if "apicallstatus" in col_map:
                    ws.cell(row=r, column=col_map["apicallstatus"]).value = "error"
                if "apierrormessage" in col_map:
                    ws.cell(row=r, column=col_map["apierrormessage"]).value = err_msg

        except Exception as ex:
            if "apicallstatus" in col_map:
                ws.cell(row=r, column=col_map["apicallstatus"]).value = "error"
            if "apierrormessage" in col_map:
                ws.cell(row=r, column=col_map["apierrormessage"]).value = str(ex)

        time.sleep(0.05)

    print(f"[2/2] Saving updated spreadsheet ({processed_count} rows processed)...")
    wb.save(EXCEL_FILE)
    print("Sync complete!")

if __name__ == "__main__":
    run_deduction_sync()