import sys
import os
import concurrent.futures
import asyncio
import json
import time
from flask import Flask, request, render_template_string, jsonify
from threading import Lock

try:
    from loguru import logger
    logger.disable("gemini_webapi")
except ImportError:
    pass

# ==================================================================================
# [SETUP] PATH CONFIGURATION
# ==================================================================================
current_dir = os.path.dirname(os.path.abspath(__file__))
gemini_src_path = os.path.join(current_dir, 'Gemini-API', 'src')

if os.path.exists(gemini_src_path):
    sys.path.append(gemini_src_path)
    print(f"[DEBUG] Added to python path: {gemini_src_path}")
else:
    print(f"[ERROR] Could not find folder: {gemini_src_path}")
    print("Please check if you cloned the repo correctly.")
    sys.exit(1)

# [SETUP] IMPORT CLIENT AND APPLY FIXES
try:
    try:
        from enum import StrEnum
    except ImportError:
        from enum import Enum
        class StrEnum(str, Enum):
            pass

    from gemini_webapi import GeminiClient
    import gemini_webapi.utils

    # [FIX] Disable auto-loading of browser cookies
    gemini_webapi.utils.load_browser_cookies = lambda: {}

    print("[DEBUG] Successfully imported GeminiClient and disabled browser scanning!")
except ImportError as e:
    print(f"[CRITICAL ERROR] Import failed: {e}")
    sys.exit(1)

app = Flask(__name__)

# ==================================================================================
# [CONFIG] FILE SETTINGS
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
    """
    Runs the Async Gemini Client in a fresh event loop for this thread.
    """
    print(f"\n[DEBUG] --- Starting Gemini Task ---")

    # 1. Load Cookies
    if not os.path.exists(COOKIE_FILE):
        return "Error: 'gemini_cookies.json' not found."

    try:
        with open(COOKIE_FILE, 'r') as f:
            raw_data = json.load(f)
        if isinstance(raw_data, list):
            file_cookies = {c['name']: c['value'] for c in raw_data if 'name' in c and 'value' in c}
        else:
            file_cookies = raw_data
    except Exception as e:
        return f"Error reading cookie file: {e}"

    cookie_1psid = file_cookies.get("__Secure-1PSID")
    cookie_1psidts = file_cookies.get("__Secure-1PSIDTS")

    if not cookie_1psid:
        return "Error: __Secure-1PSID missing from cookie file."

    # 2. Define the async workflow as a single function
    #    This ensures 'client' is opened and closed within the SAME loop cycle.
    async def task_workflow():
        client = None
        try:
            print("[DEBUG] Initializing GeminiClient...")
            client = GeminiClient(
                secure_1psid=cookie_1psid,
                secure_1psidts=cookie_1psidts,
            )

            # Inject cookies
            for k, v in file_cookies.items():
                if k not in client.cookies:
                    client.cookies[k] = v

            print("[DEBUG] Connecting (Init)...")
            await client.init(timeout=30)

            print("[DEBUG] Generating Content...")
            response = await client.generate_content(question)
            return response.text

        except Exception as e:
            # We catch the exception inside the async task to return it safely
            return f"ERROR_INTERNAL: {str(e)}"

        finally:
            # Ensure client is closed before we leave the loop
            if client:
                print("[DEBUG] Closing Gemini client...")
                await client.close()

    # 3. Create a fresh event loop for this thread and run the task
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)

    start_time = time.time()
    try:
        result = loop.run_until_complete(task_workflow())
        return result
    except Exception as e:
        return f"Error executing loop: {e}"
    finally:
        loop.close()
        elapsed = time.time() - start_time
        print(f"[DEBUG] --- Task finished in {elapsed:.2f} seconds ---")


# Global variables for rate limiting
request_timestamps = []
request_lock = Lock()

RATE_LIMIT_QUOTA = 3    # requests
RATE_LIMIT_WINDOW = 20  # seconds

@app.route('/api/ask-gpt', methods=['POST'])
def ask_gpt():
    global request_timestamps

    print("\n[DEBUG] [API] Received POST request at /api/ask-gpt")

    # ==============================================================================
    # [RATE LIMIT CHECK]
    # ==============================================================================
    with request_lock:
        current_time = time.time()
        # Filter timestamps: Keep only those within the last 20 seconds
        request_timestamps = [t for t in request_timestamps if t > (current_time - RATE_LIMIT_WINDOW)]

        if len(request_timestamps) >= RATE_LIMIT_QUOTA:
            # Calculate wait time based on the oldest request in the current window
            oldest_time = request_timestamps[0]
            wait_time = int(RATE_LIMIT_WINDOW - (current_time - oldest_time)) + 1

            msg = f"Rate limit active. Please wait {wait_time} seconds."
            print(f"[WARN] [API] {msg}")
            return jsonify({
                "status": "error",
                "message": msg,
                "wait_seconds": wait_time
            }), 429

        request_timestamps.append(current_time)
    # ==============================================================================

    try:
        data = request.json
        if not data:
            return jsonify({"error": "Invalid JSON"}), 400

        user_prompt = data.get('prompt') or data.get('question')
        if not user_prompt:
            return jsonify({"error": "No prompt provided"}), 400

        print("[DEBUG] [API] Delegating to worker thread...")

        # Create a fresh thread for the task
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(run_async_gemini_task, user_prompt)
            bot_response = future.result(timeout=DEFAULT_TIMEOUT)

        if bot_response.startswith("Error") or "ERROR_INTERNAL" in bot_response:
             print(f"[ERROR] [API] Task failed: {bot_response}")
             return jsonify({"status": "error", "message": bot_response}), 500

        return jsonify({
            "status": "success",
            "response": bot_response,
            "answer": bot_response
        })

    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

@app.route('/', methods=['GET', 'POST'])
def index():
    answer = ""
    error = ""
    question = ""

    if request.method == 'POST':
        question = request.form['question']
        print(f"\n[DEBUG] [WEB] Received form submission...")

        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(run_async_gemini_task, question)
            try:
                answer = future.result(timeout=DEFAULT_TIMEOUT)
                if answer.startswith("Error") or "ERROR_INTERNAL" in answer:
                    error = answer
                    answer = ""
            except Exception as e:
                error = f"Server Timeout or Error: {str(e)}"

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

if __name__ == '__main__':
    print("[DEBUG] Starting Flask server on 0.0.0.0:5000...")
    app.run(host='0.0.0.0', debug=True, port=5000)