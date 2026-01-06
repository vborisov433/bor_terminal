import sys
import os
import concurrent.futures
import logging
import time # Added for timing debugs

# Path setup
sys.path.append(os.path.join(os.path.dirname(__file__), 'gpt4free'))

from flask import Flask, request, render_template_string, jsonify
from g4f.client import Client

app = Flask(__name__)
client = Client()

DEFAULT_TIMEOUT = 220
DEFAULT_PROVIDER = "blackboxai"
DEFAULT_MODEL = ""

HTML_TEMPLATE = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GPT4Free Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h2 class="mb-4">🤖 GPT4Free Chat</h2>
    <form method="post">
        <div class="mb-3">
            <label for="question" class="form-label">Enter your question:</label>
            <textarea name="question" id="question" class="form-control" rows="6" required>{{ question }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">Ask GPT</button>
    </form>

    {% if answer or error %}
    <div class="mt-5">
        <h4>💡 GPT Response:</h4>
        <div class="card shadow-sm {{ 'border-danger' if error else 'border-success' }}">
            <div class="card-body">
                {% if error %}
                  <p class="text-danger mb-3">{{ error }}</p>
                  <form method="get">
                      <button type="submit" class="btn btn-outline-secondary">
                          Reload
                      </button>
                  </form>
                {% else %}
                  <pre class="mb-0" style="white-space: pre-wrap;">{{ answer }}</pre>
                {% endif %}
            </div>
        </div>
    </div>
    {% endif %}
</div>
</body>
</html>
'''

def ask_gpt_dynamic(question, model):
    print(f"\n[DEBUG] --- ask_gpt_dynamic started ---")
    print(f"[DEBUG] Requested Model: {model}")
    print(f"[DEBUG] Input Question Length: {len(question)} chars")

    chunks = split_text_into_chunks(question)
    print(f"[DEBUG] Text split into {len(chunks)} chunk(s).")

    # Prepare messages from chunks
    messages = [{"role": "user", "content": chunk} for chunk in chunks]

    print(f"[DEBUG] Sending request to Provider: {DEFAULT_PROVIDER}...")
    start_time = time.time()

    try:
        # Note: I changed model=DEFAULT_MODEL to model=model so the API argument works
        response = client.chat.completions.create(
            messages=messages,
            web_search=False
        )
        elapsed = time.time() - start_time
        print(f"[DEBUG] Response received in {elapsed:.2f} seconds.")

        content = response.choices[0].message.content
        print(f"[DEBUG] Response content length: {len(content)} chars")
        return content

    except Exception as e:
        print(f"[DEBUG] !!! Error inside ask_gpt_dynamic: {e}")
        raise e


def split_text_into_chunks(text, max_length=3000):
    """
    Splits the input text into chunks of up to max_length characters,
    ensuring that no word is cut during the process.
    """
    words = text.split()
    chunks = []
    current_chunk = ""

    for word in words:
        # Check if adding the next word would exceed the max_length
        if len(current_chunk) + len(word) + 1 > max_length:
            chunks.append(current_chunk.strip())
            current_chunk = word + " "
        else:
            current_chunk += word + " "

    # Add the last chunk if it's not empty
    if current_chunk:
        chunks.append(current_chunk.strip())

    return chunks

@app.route('/', methods=['GET', 'POST'])
def index():
    answer = ""
    error = ""
    question = ""

    if request.method == 'POST':
        print(f"\n[DEBUG] === POST request received on Index (/) ===")
        question = request.form['question']
        print(f"[DEBUG] Question snippet: {question[:50]}...")

        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(ask_gpt_dynamic, question, DEFAULT_MODEL)
            try:
                answer = future.result(timeout=DEFAULT_TIMEOUT)
                print("[DEBUG] Future result retrieved successfully.")
            except concurrent.futures.TimeoutError:
                print("[DEBUG] !!! Request Timed Out !!!")
                error = "Sorry, the request took too long and timed out. Please try again or reload."
            except Exception as e:
                print(f"[DEBUG] !!! Unexpected Error: {e}")
                error = f"An unexpected error occurred: {str(e)}"
    else:
        print(f"\n[DEBUG] GET request received on Index (/)")

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

log_dir = './LOGS'
os.makedirs(log_dir, exist_ok=True)
log_file_path = os.path.join(log_dir, 'log.txt')

logging.basicConfig(
    filename=log_file_path,
    level=logging.INFO,
    format='%(asctime)s %(levelname)s: %(message)s',
)

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask_gpt():
    print(f"\n[DEBUG] === API Request received at /api/ask-gpt ===")

    if not request.is_json:
        print("[DEBUG] Error: Content-Type is not application/json")
        return jsonify({"error": "Content-Type must be application/json"}), 400

    data = request.get_json()
    question = data.get("question", "")

    # Get model from query parameter, fallback to default
    model = request.args.get("model", DEFAULT_MODEL)
    print(f"[DEBUG] API Params - Model: {model}")

    if not question:
        print("[DEBUG] Error: Missing question in body")
        return jsonify({"error": "Missing 'question' in the request body."}), 400

    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(ask_gpt_dynamic, question, model)
        try:
            answer = future.result(timeout=DEFAULT_TIMEOUT)
            print("[DEBUG] API execution successful, returning JSON.")
            return jsonify({"answer": answer, "model": model})
        except concurrent.futures.TimeoutError:
            print("[DEBUG] !!! API Request Timed Out")
            return jsonify({"answer": "Request timed out. Try again later.", "model": model}), 504
        except Exception as e:
            print(f"[DEBUG] !!! API Internal Error: {e}")
            return jsonify({"answer": f"Internal error: {str(e)}", "model": model}), 500


if __name__ == '__main__':
    print("[DEBUG] Server starting on 0.0.0.0:5000...")
    app.run(host='0.0.0.0', debug=True, port=5000)