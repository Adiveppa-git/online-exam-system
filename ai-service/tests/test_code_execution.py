import pytest
from app.services.code_executor import CodeExecutor

def test_python_valid_execution():
    code = "print('Hello from Sandbox!')"
    res = CodeExecutor.execute("python", code)
    assert res["status"] == "success"
    assert "Hello from Sandbox!" in res["stdout"]
    assert res["exit_code"] == 0
    assert not res["timeout"]

def test_python_with_stdin():
    code = "name = input().strip()\nprint(f'Hello, {name}!')"
    res = CodeExecutor.execute("python", code, stdin="Alice")
    assert res["status"] == "success"
    assert "Hello, Alice!" in res["stdout"]

def test_python_runtime_error():
    code = "x = 1 / 0"
    res = CodeExecutor.execute("python", code)
    assert res["status"] == "runtime_error"
    assert "ZeroDivisionError" in res["stderr"]
    assert res["exit_code"] != 0

def test_python_timeout():
    code = "import time\ntime.sleep(5)"
    res = CodeExecutor.execute("python", code, timeout=1.0)
    assert res["status"] == "timeout"
    assert res["timeout"] is True
    assert "Time Limit Exceeded" in res["stderr"]

def test_java_valid_execution():
    code = """
    public class Solution {
        public static void main(String[] args) {
            System.out.println("Java Sandbox OK");
        }
    }
    """
    res = CodeExecutor.execute("java", code)
    assert res["status"] == "success"
    assert "Java Sandbox OK" in res["stdout"]
    assert res["exit_code"] == 0

def test_java_with_stdin():
    code = """
    import java.util.Scanner;
    public class Solution {
        public static void main(String[] args) {
            Scanner sc = new Scanner(System.in);
            int n = sc.nextInt();
            System.out.println(n * 10);
        }
    }
    """
    res = CodeExecutor.execute("java", code, stdin="42")
    assert res["status"] == "success"
    assert "420" in res["stdout"]

def test_java_compilation_error():
    code = """
    public class Solution {
        public static void main(String[] args) {
            int a = "string without cast"; // Compilation error
        }
    }
    """
    res = CodeExecutor.execute("java", code)
    assert res["status"] == "compilation_error"
    assert "incompatible types" in res["stderr"]

def test_check_sample_cases():
    code = """
def solve():
    s = input().strip()
    if s == s[::-1]:
        print("true")
    else:
        print("false")
solve()
"""
    test_cases = [
        {"input": "racecar", "output": "true"},
        {"input": "hello", "output": "false"}
    ]
    res = CodeExecutor.check_test_cases("python", code, test_cases)
    assert res["status"] == "success"
    assert res["total_cases"] == 2
    assert res["passed_cases"] == 2
    assert res["all_passed"] is True
    assert res["results"][0]["passed"] is True
    assert res["results"][1]["passed"] is True

def test_check_sample_cases_failure():
    code = "print('wrong answer')"
    test_cases = [
        {"input": "29", "output": "true"}
    ]
    res = CodeExecutor.check_test_cases("python", code, test_cases)
    assert res["all_passed"] is False
    assert res["passed_cases"] == 0
    assert res["results"][0]["passed"] is False
    assert "wrong answer" in res["results"][0]["actual_output"]
