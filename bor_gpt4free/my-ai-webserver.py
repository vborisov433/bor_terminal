import sys
import os
import concurrent.futures
import asyncio
import json
from flask import Flask, request, render_template_string

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
    from gemini_webapi import GeminiClient
    import gemini_webapi.utils

    # [FIX] Disable auto-loading of browser cookies to stop Permission Denied errors
    # We overwrite the function with an empty lambda so it does nothing.
    gemini_webapi.utils.load_browser_cookies = lambda: {}

    print("[DEBUG] Successfully imported GeminiClient and disabled browser scanning!")
except ImportError as e:
    print(f"[CRITICAL ERROR] Import failed: {e}")
    print("Try running: pip install -r Gemini-API/requirements.txt")
    sys.exit(1)

app = Flask(__name__)

# ==================================================================================
# [CONFIG] FILE SETTINGS
# ==================================================================================
COOKIE_FILE = "gemini_cookies.json" # Ensure this file exists with your JSON content
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
    Runs the Async Gemini Client in a synchronous wrapper using cookies from file.
    """
    # 0. Load Cookies from File
    if not os.path.exists(COOKIE_FILE):
        return "Error: 'gemini_cookies.json' not found. Please create it with your cookies."

    try:
        with open(COOKIE_FILE, 'r') as f:
            raw_data = json.load(f)

        # [FIX] Handle List vs Dictionary
        # If the JSON is a list (from Chrome export), flatten it to {name: value}
        if isinstance(raw_data, list):
            file_cookies = {c['name']: c['value'] for c in raw_data if 'name' in c and 'value' in c}
        else:
            file_cookies = raw_data

    except Exception as e:
        return f"Error reading cookie file: {e}"

    # Extract critical cookies variables from the loaded dictionary
    cookie_1psid = file_cookies.get("__Secure-1PSID")
    cookie_1psidts = file_cookies.get("__Secure-1PSIDTS")

    if not cookie_1psid:
        return "Error: __Secure-1PSID missing from cookie file."

    # Start Async Loop
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)

    response_text = ""
    client = None

    try:
        # 1. Initialize Client with Loaded Variables
        print("[DEBUG] Initializing GeminiClient with file cookies...")
        client = GeminiClient(
            secure_1psid=cookie_1psid,
            secure_1psidts=cookie_1psidts,
        )

        # 2. Inject ALL cookies from file (including 1PSIDCC)
        for k, v in file_cookies.items():
            if k not in client.cookies:
                client.cookies[k] = v

        # 3. Perform Handshake (init)
        # Timeout slightly increased to ensure connection stability
        loop.run_until_complete(client.init(timeout=30))

        # 4. Generate Content
        print(f"[DEBUG] Generating content for: {question[:30]}...")
        response = loop.run_until_complete(client.generate_content(question))
        response_text = response.text

    except Exception as e:
        error_msg = str(e)
        if "cookie" in error_msg.lower() or "auth" in error_msg.lower():
            response_text = (
                f"AUTHENTICATION ERROR: Google rejected the cookies.\n"
                f"Please refresh your 'gemini_cookies.json' file.\n"
                f"Details: {error_msg}"
            )
        else:
            response_text = f"Error executing request: {error_msg}"
            print(f"[ERROR] {error_msg}")

    finally:
        # 5. Cleanup
        if client:
            try:
                loop.run_until_complete(asyncio.wait_for(client.close(), timeout=2))
            except:
                pass
        loop.close()

    return response_text

@app.route('/', methods=['GET', 'POST'])
def index():
    answer = ""
    error = ""
    question = ""

    if request.method == 'POST':
        question = request.form['question']

        # Use ThreadPool to prevent blocking the Flask main thread
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(run_async_gemini_task, question)
            try:
                answer = future.result(timeout=DEFAULT_TIMEOUT)
                if answer.startswith("Error") or answer.startswith("AUTHENTICATION ERROR"):
                    error = answer
                    answer = ""
            except Exception as e:
                error = f"Server Timeout or Error: {str(e)}"

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

if __name__ == '__main__':
    print("[DEBUG] Starting server on 0.0.0.0:5000")
    app.run(host='0.0.0.0', debug=True, port=5000)