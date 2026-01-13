import traceback  # <--- ADDED AS REQUESTED
import sys
import os
import concurrent.futures
import asyncio
import json
import time
from flask import Flask, request, render_template_string, jsonify
from threading import Lock

# ==================================================================================
# [SETUP] PATH CONFIGURATION
# ==================================================================================
current_dir = os.path.dirname(os.path.abspath(__file__))
gemini_src_path = os.path.join(current_dir, 'Gemini-API', 'src')

if os.path.exists(gemini_src_path):
    sys.path.append(gemini_src_path)
else:
    print(f"[CRITICAL] Missing folder: {gemini_src_path}")
    sys.exit(1)

# [SETUP] IMPORT CLIENT
try:
    try:
        from enum import StrEnum
    except ImportError:
        from enum import Enum
        class StrEnum(str, Enum): pass

    from gemini_webapi import GeminiClient
    import gemini_webapi.utils
    gemini_webapi.utils.load_browser_cookies = lambda: {}
except ImportError as e:
    print(f"[CRITICAL] Import failed: {e}")
    sys.exit(1)

app = Flask(__name__)

# ==================================================================================
# [CONFIG]
# ==================================================================================
COOKIE_FILE = "gemini_cookies.json"
DEFAULT_TIMEOUT = 120

HTML_TEMPLATE = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gemini Web Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h2 class="mb-4">🤖 Gemini Web Chat</h2>
    <form method="post">
        <div class="mb-3">
            <label for="question" class="form-label">Enter your question:</label>
            <textarea name="question" id="question" class="form-control" rows="6" required>{{ question }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">Ask Gemini</button>
    </form>
    {% if answer or error %}
    <div class="mt-5">
        <h4>💡 Response:</h4>
        <div class="card shadow-sm {{ 'border-danger' if error else 'border-success' }}">
            <div class="card-body">
                {% if error %}
                  <p class="text-danger">{{ error }}</p>
                {% else %}
                  <pre style="white-space: pre-wrap;">{{ answer }}</pre>
                {% endif %}
            </div>
        </div>
    </div>
    {% endif %}
</div>
</body>
</html>
'''

def run_async_gemini_task(question):
    """ Runs the Gemini Client in a fresh event loop. """

    # Minimal Log: Task Start
    print(f"[TASK] Processing ...")

    if not os.path.exists(COOKIE_FILE):
        return "Error: 'gemini_cookies.json' missing."

    try:
        with open(COOKIE_FILE, 'r') as f:
            raw = json.load(f)
        file_cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw
    except Exception as e:
        return f"Error reading cookies: {e}"

    cookie_1psid = file_cookies.get("__Secure-1PSID")
    cookie_1psidts = file_cookies.get("__Secure-1PSIDTS")

    if not cookie_1psid:
        return "Error: Missing __Secure-1PSID."

    async def task_workflow():
        client = None
        try:
            client = GeminiClient(secure_1psid=cookie_1psid, secure_1psidts=cookie_1psidts)
            for k, v in file_cookies.items():
                if k not in client.cookies: client.cookies[k] = v

            await client.init(timeout=DEFAULT_TIMEOUT)

            # --- DEBUGGING "FAILED TO GENERATE CONTENTS" ---
            response = await client.generate_content(question)

            # The library raises ValueError when accessing .text if payload is empty/invalid
            try:
                return response.text
            except ValueError:
                # If we get here, Google returned a 200 OK but refused to give content (Safety/Cookies)
                print(f"[Gemini] ValueError: Content generation failed.")
                print(f"[Gemini] Response Metadata: {response.__dict__}") # Log available metadata
                return "Error: Google refused to generate content (ValueError). This usually means your cookies are stale or the prompt triggered a safety filter."

        except Exception as e:
            # Capture library specific errors
            print(f"[Gemini Internal Error] {str(e)}")
            return f"ERROR_INTERNAL: {str(e)}"
        finally:
            if client: await client.close()

    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    start = time.time()

    try:
        result = loop.run_until_complete(task_workflow())
        elapsed = time.time() - start
        print(f"[TASK] Completed in {elapsed:.2f}s") # Minimal Log: Task End
        return result
    except Exception as e:
        return f"Loop Error: {e}"
    finally:
        loop.close()
# --- Configuration ---
request_timestamps = []
request_lock = Lock()
RATE_QUOTA = 3
RATE_WINDOW = 20
DEFAULT_TIMEOUT = 30

@app.route('/api/ask-gpt', methods=['POST'])
def ask_gpt():
    global request_timestamps

    # 1. Minimal Log: Incoming Request
    print("\n[API] POST /api/ask-gpt")

    # 2. Rate Limiting Logic
    with request_lock:
        now = time.time()
        # Filter out timestamps older than the window
        request_timestamps = [t for t in request_timestamps if t > (now - RATE_WINDOW)]

        if len(request_timestamps) >= RATE_QUOTA:
            wait = int(RATE_WINDOW - (now - request_timestamps[0])) + 1
            print(f"[LIMIT] Throttled. Wait {wait}s")
            return jsonify({
                "status": "error",
                "message": f"Rate limit exceeded. Please wait {wait}s.",
                "wait_seconds": wait
            }), 429

        request_timestamps.append(now)

    # 3. Initialize variables for debug safety
    raw_data = "<No Data>"
    prompt = "<Prompt Not Parsed>"

    try:
        # Capture raw data immediately for debugging context
        raw_data = request.get_data(as_text=True)

        # Attempt to parse JSON
        # If the user sends invalid JSON, request.json might be None or raise 400
        data = request.get_json(silent=True)

        if data is None:
            print(f"[API] Error: Invalid JSON received. Raw: {raw_data[:50]}...")
            return jsonify({"error": "Invalid JSON format"}), 400

        prompt = data.get('prompt') or data.get('question')

        if not prompt:
            print("[API] Error: No prompt provided in JSON")
            return jsonify({"error": "Missing 'prompt' or 'question' field"}), 400

        # Run the heavy task
        # Note: Ensure run_async_gemini_task is synchronous or wrapped correctly
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(run_async_gemini_task, prompt)
            bot_response = future.result(timeout=DEFAULT_TIMEOUT)

        if bot_response.startswith("Error") or "ERROR_INTERNAL" in bot_response:
             print(f"[API] Worker Failed: {bot_response[:50]}...")
             return jsonify({"status": "error", "message": "Worker Failed"}), 500

        return jsonify({"status": "success", "response": bot_response, "answer": bot_response})

    # 4. CRITICAL EXCEPTION HANDLER
    except Exception as e:
        error_trace = traceback.format_exc()

        print("\n" + "="*30)
        print(" [API] !!! CRITICAL EXCEPTION !!!")
        print("="*30)
        print(f"Error Type:    {type(e).__name__}")
        print(f"Error Message: {str(e)}")
        print("-" * 30)
        print(" [FAILED PROMPT / DATA] ")

        # Logic to print whatever we managed to capture
        if prompt != "<Prompt Not Parsed>":
            print(f"> PROMPT: {prompt}")
        else:
            print(f"> RAW DATA (JSON Fail): {raw_data}")

        print("-" * 30)
        print(" [STACK TRACE] ")
        print(error_trace)
        print("="*30 + "\n")

        # Return a JSON useful for API consumers (like Postman)
        return jsonify({
            "status": "error",
            "message": "Internal Server Error",
            "debug_info": {
                "error_type": type(e).__name__,
                "input_captured": prompt if prompt != "<Prompt Not Parsed>" else raw_data
            }
        }), 500


@app.route('/', methods=['GET', 'POST'])
def index():
    answer, error, question = "", "", ""

    if request.method == 'POST':
        question = request.form['question']
        print(f"\n[WEB] Prompt: {question[:30]}...")

        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(run_async_gemini_task, question)
            try:
                answer = future.result(timeout=DEFAULT_TIMEOUT)
                if answer.startswith("Error") or "ERROR_INTERNAL" in answer:
                    error = answer
                    answer = ""
            except Exception as e:
                error = f"Timeout/Error: {str(e)}"

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

if __name__ == '__main__':
    print("[SYS] Server running at http://0.0.0.0:5000")
    app.run(host='0.0.0.0', debug=True, port=5000)