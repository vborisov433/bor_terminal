from flask import Flask, request, render_template_string, jsonify
from g4f.client import Client
import concurrent.futures

app = Flask(__name__)
client = Client()

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

def ask_gpt(question):
#     response = client.chat.completions.create(
#         model="gpt-4o",
#         messages=[{"role": "user", "content": question}],
#         web_search=False
#     )

    response = client.chat.completions.create(
        model="gpt-4.1",  # Specify the model as gpt-4.1
        messages=[{"role": "user", "content": question}],
        web_search=False,
        provider="PollinationsAI"  # Explicitly use the Polinations provider
    )

    return response.choices[0].message.content

@app.route('/', methods=['GET', 'POST'])
def index():
    answer = ""
    error = ""
    question = ""
    if request.method == 'POST':
        question = request.form['question']
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(ask_gpt, question)
            try:
                answer = future.result(timeout=15)  # 15 seconds timeout
            except concurrent.futures.TimeoutError:
                error = "Sorry, the request took too long and timed out. Please try again or reload."
            except Exception as e:
                error = f"An unexpected error occurred: {str(e)}"
    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask_gpt():
    if not request.is_json:
        return jsonify({"error": "Content-Type must be application/json"}), 400

    data = request.get_json()
    question = data.get("question", "")
    if not question:
        return jsonify({"error": "Missing 'question' in the request body."}), 400

    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(ask_gpt, question)
        try:
            answer = future.result(timeout=120)
            return jsonify({"answer": answer})
        except concurrent.futures.TimeoutError:
            return jsonify({"answer": "Request timed out. Try again later."}), 504
        except Exception as e:
            return jsonify({"answer": f"Internal error: {str(e)}"}), 500

if __name__ == '__main__':
    app.run(debug=True, port=5000)
