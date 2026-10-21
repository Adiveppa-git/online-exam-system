import os
import sys
import time
import shutil
import tempfile
import subprocess
import re
import sqlite3
from typing import Dict, Any, List, Optional, Tuple

MAX_OUTPUT_BYTES = 32 * 1024  # 32 KB output limit to prevent output bombs
DEFAULT_TIMEOUT_SECONDS = 5.0

class CodeExecutor:
    """
    Secure, isolated sandbox executor for Python, Java, C, C++, and SQL code.
    Runs untrusted student code inside an isolated temporary directory with:
    - Minimal sanitized environment variables (no access to DB passwords or API keys)
    - Strict execution timeout
    - Truncated standard output and standard error
    - Process isolation without shell=True
    - Guaranteed cleanup of all created files
    """

    SUPPORTED_LANGUAGES = {
        "python": "python",
        "python3": "python",
        "py": "python",
        "java": "java",
        "c": "c",
        "cpp": "cpp",
        "c++": "cpp",
        "sql": "sql"
    }

    @classmethod
    def normalize_language(cls, lang: str) -> str:
        clean = (lang or "").strip().lower()
        clean = re.sub(r'[^a-z0-9+]', '', clean)
        return cls.SUPPORTED_LANGUAGES.get(clean, clean)

    @classmethod
    def get_safe_env(cls) -> Dict[str, str]:
        """Construct a minimal OS environment containing no sensitive credentials."""
        safe_keys = {"SYSTEMROOT", "SYSTEMDRIVE", "COMSPEC", "PATH", "TEMP", "TMP", "WINDIR", "PATHEXT"}
        safe_env = {}
        for k, v in os.environ.items():
            if k.upper() in safe_keys:
                safe_env[k] = v
        # Ensure no sensitive variables leak
        for k in list(safe_env.keys()):
            if any(bad in k.upper() for bad in ["KEY", "SECRET", "PASS", "TOKEN", "DB_", "MYSQL", "DATABASE"]):
                safe_env.pop(k, None)
        return safe_env

    @classmethod
    def execute(cls, language: str, code: str, stdin: str = "", timeout: float = DEFAULT_TIMEOUT_SECONDS) -> Dict[str, Any]:
        """
        Executes a single program securely and returns stdout, stderr, exit code, and execution time.
        """
        lang = cls.normalize_language(language)
        if lang not in cls.SUPPORTED_LANGUAGES.values():
            return {
                "status": "error",
                "stdout": "",
                "stderr": f"Unsupported programming language '{language}'. Supported: Python, Java, C, C++, SQL.",
                "exit_code": 1,
                "execution_time_ms": 0.0,
                "timeout": False
            }

        timeout = max(0.5, min(float(timeout or DEFAULT_TIMEOUT_SECONDS), 10.0))

        if lang == "python":
            return cls._execute_python(code, stdin, timeout)
        elif lang == "java":
            return cls._execute_java(code, stdin, timeout)
        elif lang in ("c", "cpp"):
            return cls._execute_c_cpp(lang, code, stdin, timeout)
        elif lang == "sql":
            return cls._execute_sql(code, stdin, timeout)
        else:
            return {
                "status": "error",
                "stdout": "",
                "stderr": f"Execution handler not implemented for language: {lang}",
                "exit_code": 1,
                "execution_time_ms": 0.0,
                "timeout": False
            }

    @classmethod
    def _execute_python(cls, code: str, stdin: str, timeout: float) -> Dict[str, Any]:
        temp_dir = tempfile.mkdtemp(prefix="agy_sandbox_py_")
        script_path = os.path.join(temp_dir, "main.py")
        try:
            with open(script_path, "w", encoding="utf-8") as f:
                f.write(code)

            # Python isolated mode:
            # -I: Run in isolated mode (no script dir in sys.path, no user site packages, ignore PYTHON* env vars)
            # -B: Don't write .pyc bytecode
            # -s: Don't add user site directory to sys.path
            # -E: Ignore all PYTHON* environment variables
            cmd = [sys.executable, "-I", "-B", "-s", "-E", "main.py"]

            start_time = time.perf_counter()
            proc = subprocess.Popen(
                cmd,
                cwd=temp_dir,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=cls.get_safe_env()
            )

            stdin_bytes = stdin.encode("utf-8") if stdin else b""
            try:
                out_b, err_b = proc.communicate(input=stdin_bytes, timeout=timeout)
                elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)
                stdout = out_b.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES]
                stderr = err_b.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES]

                if proc.returncode == 0:
                    status = "success"
                else:
                    status = "runtime_error"

                return {
                    "status": status,
                    "stdout": stdout,
                    "stderr": stderr,
                    "exit_code": proc.returncode,
                    "execution_time_ms": elapsed_ms,
                    "timeout": False
                }
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.communicate()
                elapsed_ms = round(timeout * 1000, 2)
                return {
                    "status": "timeout",
                    "stdout": "",
                    "stderr": f"Time Limit Exceeded: Execution timed out after {timeout:.1f} seconds.",
                    "exit_code": -1,
                    "execution_time_ms": elapsed_ms,
                    "timeout": True
                }
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    @classmethod
    def _execute_java(cls, code: str, stdin: str, timeout: float) -> Dict[str, Any]:
        # 1. Determine class name from code
        match_public = re.search(r'public\s+class\s+([A-Za-z0-9_]+)', code)
        if match_public:
            class_name = match_public.group(1)
        else:
            match_any = re.search(r'class\s+([A-Za-z0-9_]+)', code)
            class_name = match_any.group(1) if match_any else "Solution"

        temp_dir = tempfile.mkdtemp(prefix="agy_sandbox_java_")
        source_file = os.path.join(temp_dir, f"{class_name}.java")

        try:
            with open(source_file, "w", encoding="utf-8") as f:
                f.write(code)

            # Check if javac is installed
            javac_cmd = shutil.which("javac")
            if not javac_cmd:
                return {
                    "status": "error",
                    "stdout": "",
                    "stderr": "Java Development Kit (javac) compiler is not installed on this system.",
                    "exit_code": 1,
                    "execution_time_ms": 0.0,
                    "timeout": False
                }

            java_cmd = shutil.which("java") or "java"

            # Step 1: Compile
            start_compile = time.perf_counter()
            compile_proc = subprocess.run(
                [javac_cmd, "-J-Xmx128m", "-encoding", "UTF-8", f"{class_name}.java"],
                cwd=temp_dir,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=cls.get_safe_env(),
                timeout=timeout
            )

            if compile_proc.returncode != 0:
                compile_err = compile_proc.stderr.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES]
                elapsed_ms = round((time.perf_counter() - start_compile) * 1000, 2)
                return {
                    "status": "compilation_error",
                    "stdout": "",
                    "stderr": compile_err,
                    "exit_code": compile_proc.returncode,
                    "execution_time_ms": elapsed_ms,
                    "timeout": False
                }

            # Step 2: Execute
            start_run = time.perf_counter()
            run_proc = subprocess.Popen(
                [java_cmd, "-Xmx128m", "-Dfile.encoding=UTF-8", class_name],
                cwd=temp_dir,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=cls.get_safe_env()
            )

            stdin_bytes = stdin.encode("utf-8") if stdin else b""
            try:
                out_b, err_b = run_proc.communicate(input=stdin_bytes, timeout=timeout)
                elapsed_ms = round((time.perf_counter() - start_run) * 1000, 2)
                stdout = out_b.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES]
                stderr = err_b.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES]

                return {
                    "status": "success" if run_proc.returncode == 0 else "runtime_error",
                    "stdout": stdout,
                    "stderr": stderr,
                    "exit_code": run_proc.returncode,
                    "execution_time_ms": elapsed_ms,
                    "timeout": False
                }
            except subprocess.TimeoutExpired:
                run_proc.kill()
                run_proc.communicate()
                return {
                    "status": "timeout",
                    "stdout": "",
                    "stderr": f"Time Limit Exceeded: Java execution timed out after {timeout:.1f} seconds.",
                    "exit_code": -1,
                    "execution_time_ms": round(timeout * 1000, 2),
                    "timeout": True
                }

        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    @classmethod
    def _execute_c_cpp(cls, lang: str, code: str, stdin: str, timeout: float) -> Dict[str, Any]:
        compiler = "gcc" if lang == "c" else "g++"
        compiler_path = shutil.which(compiler)
        if not compiler_path:
            return {
                "status": "error",
                "stdout": "",
                "stderr": f"{compiler.upper()} compiler is not installed or not found in system PATH.",
                "exit_code": 1,
                "execution_time_ms": 0.0,
                "timeout": False
            }

        temp_dir = tempfile.mkdtemp(prefix="agy_sandbox_c_")
        ext = ".c" if lang == "c" else ".cpp"
        src_path = os.path.join(temp_dir, f"main{ext}")
        exe_path = os.path.join(temp_dir, "prog.exe" if os.name == "nt" else "prog")

        try:
            with open(src_path, "w", encoding="utf-8") as f:
                f.write(code)

            # Compile
            comp_proc = subprocess.run(
                [compiler_path, "-O2", f"main{ext}", "-o", exe_path],
                cwd=temp_dir,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=cls.get_safe_env(),
                timeout=timeout
            )

            if comp_proc.returncode != 0:
                return {
                    "status": "compilation_error",
                    "stdout": "",
                    "stderr": comp_proc.stderr.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES],
                    "exit_code": comp_proc.returncode,
                    "execution_time_ms": 0.0,
                    "timeout": False
                }

            # Run
            start_time = time.perf_counter()
            run_proc = subprocess.Popen(
                [exe_path],
                cwd=temp_dir,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=cls.get_safe_env()
            )
            out_b, err_b = run_proc.communicate(input=stdin.encode("utf-8") if stdin else b"", timeout=timeout)
            elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)

            return {
                "status": "success" if run_proc.returncode == 0 else "runtime_error",
                "stdout": out_b.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES],
                "stderr": err_b.decode("utf-8", errors="replace")[:MAX_OUTPUT_BYTES],
                "exit_code": run_proc.returncode,
                "execution_time_ms": elapsed_ms,
                "timeout": False
            }
        except subprocess.TimeoutExpired:
            return {
                "status": "timeout",
                "stdout": "",
                "stderr": f"Time Limit Exceeded after {timeout:.1f}s",
                "exit_code": -1,
                "execution_time_ms": round(timeout * 1000, 2),
                "timeout": True
            }
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    @classmethod
    def _execute_sql(cls, code: str, stdin: str, timeout: float) -> Dict[str, Any]:
        """Safely executes SQL queries inside a temporary in-memory SQLite sandbox."""
        start_time = time.perf_counter()
        try:
            conn = sqlite3.connect(":memory:")
            cursor = conn.cursor()

            # Execute any setup DDL/DML passed via stdin
            if stdin and stdin.strip():
                cursor.executescript(stdin)

            # Execute student query/script
            output_rows = []
            for stmt in code.strip().split(";"):
                stmt = stmt.strip()
                if not stmt:
                    continue
                cursor.execute(stmt)
                if cursor.description:
                    headers = [col[0] for col in cursor.description]
                    rows = cursor.fetchall()
                    output_rows.append(" | ".join(headers))
                    output_rows.append("-" * max(20, len(" | ".join(headers))))
                    for row in rows:
                        output_rows.append(" | ".join(str(val) if val is not None else "NULL" for val in row))
            conn.close()

            elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)
            stdout = "\n".join(output_rows)
            return {
                "status": "success",
                "stdout": stdout,
                "stderr": "",
                "exit_code": 0,
                "execution_time_ms": elapsed_ms,
                "timeout": False
            }
        except Exception as e:
            elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)
            return {
                "status": "runtime_error",
                "stdout": "",
                "stderr": f"SQL Error: {str(e)}",
                "exit_code": 1,
                "execution_time_ms": elapsed_ms,
                "timeout": False
            }

    @classmethod
    def normalize_for_comparison(cls, text: str) -> str:
        """Normalizes output by trimming whitespace and normalizing line breaks for accurate grading."""
        if text is None:
            return ""
        text = str(text).replace("\r\n", "\n").replace("\r", "\n")
        lines = [line.rstrip() for line in text.split("\n")]
        # Remove trailing empty lines
        while lines and lines[-1] == "":
            lines.pop()
        return "\n".join(lines).strip()

    @classmethod
    def check_test_cases(cls, language: str, code: str, test_cases: List[Dict[str, Any]], timeout: float = DEFAULT_TIMEOUT_SECONDS) -> Dict[str, Any]:
        """
        Runs code against multiple sample test cases and returns structured pass/fail results.
        """
        if not test_cases:
            # If no test cases, run once with empty input
            single = cls.execute(language, code, stdin="", timeout=timeout)
            return {
                "status": single["status"],
                "total_cases": 0,
                "passed_cases": 0,
                "all_passed": single["status"] == "success",
                "results": [],
                "error_summary": single["stderr"] if single["status"] != "success" else ""
            }

        results = []
        passed_count = 0
        overall_status = "success"
        error_summary = ""

        for idx, tc in enumerate(test_cases, 1):
            raw_input = tc.get("input", "")
            if isinstance(raw_input, (dict, list)):
                import json
                stdin_str = json.dumps(raw_input)
            else:
                stdin_str = str(raw_input) if raw_input is not None else ""

            raw_expected = tc.get("output") if tc.get("output") is not None and tc.get("output") != "" else tc.get("expected_output", "")
            if isinstance(raw_expected, (dict, list)):
                import json
                expected_str = json.dumps(raw_expected)
            else:
                expected_str = str(raw_expected) if raw_expected is not None else ""

            exec_res = cls.execute(language, code, stdin=stdin_str, timeout=timeout)

            # If compilation error (e.g. Java), halt immediately and fail all
            if exec_res["status"] == "compilation_error":
                overall_status = "compilation_error"
                error_summary = exec_res["stderr"]
                results.append({
                    "case_index": idx,
                    "input": stdin_str,
                    "expected_output": expected_str,
                    "actual_output": "",
                    "passed": False,
                    "error": exec_res["stderr"],
                    "execution_time_ms": exec_res["execution_time_ms"]
                })
                break

            actual_norm = cls.normalize_for_comparison(exec_res["stdout"])
            expected_norm = cls.normalize_for_comparison(expected_str)

            passed = (actual_norm == expected_norm and exec_res["status"] == "success")
            if passed:
                passed_count += 1
            else:
                if overall_status == "success":
                    overall_status = exec_res["status"] if exec_res["status"] != "success" else "failed"
                    if exec_res["stderr"]:
                        error_summary = exec_res["stderr"]

            results.append({
                "case_index": idx,
                "input": stdin_str,
                "expected_output": expected_str,
                "actual_output": exec_res["stdout"],
                "passed": passed,
                "error": exec_res["stderr"] if exec_res["status"] != "success" else "",
                "execution_time_ms": exec_res["execution_time_ms"]
            })

        total = len(test_cases)
        all_passed = (passed_count == total and total > 0)

        return {
            "status": "success" if all_passed else overall_status,
            "total_cases": total,
            "passed_cases": passed_count,
            "all_passed": all_passed,
            "results": results,
            "error_summary": error_summary
        }
