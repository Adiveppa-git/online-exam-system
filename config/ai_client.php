<?php
/**
 * AI Service Client
 * Handles HTTP cURL communications between PHP application and Python FastAPI AI Service.
 */

class AiClient {
    private string $baseUrl;
    private int $timeout;
    private string $apiKey;

    public function __construct(?string $baseUrl = null, int $timeout = 35, ?string $apiKey = null) {
        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        } else {
            $envUrl = getenv('AI_SERVICE_URL');
            $this->baseUrl = defined('AI_SERVICE_URL') ? rtrim(AI_SERVICE_URL, '/') : ($envUrl ? rtrim($envUrl, '/') : 'http://127.0.0.1:8001');
        }
        $this->timeout = defined('AI_SERVICE_TIMEOUT') ? AI_SERVICE_TIMEOUT : (getenv('AI_SERVICE_TIMEOUT') ? (int)getenv('AI_SERVICE_TIMEOUT') : $timeout);
        $this->apiKey = $apiKey ?? (defined('AI_SERVICE_KEY') ? AI_SERVICE_KEY : (getenv('AI_SERVICE_KEY') ?: 'dev_secret_key_change_in_production'));
    }

    public function getBaseUrl(): string {
        return $this->baseUrl;
    }

    public function checkHealth(int $customTimeout = 2): array {
        return $this->request('GET', '/health', null, $customTimeout);
    }

    public function generateQuestions($arg1, string $topic = '', string $difficulty = 'medium', int $numQuestions = 3, string $additionalContext = ''): array {
        if (is_array($arg1)) {
            $payload = $arg1;
        } else {
            $payload = [
                'subject' => $arg1,
                'topic' => $topic,
                'difficulty' => strtolower($difficulty),
                'number_of_questions' => $numQuestions,
                'additional_context' => $additionalContext
            ];
        }
        return $this->request('POST', '/api/v1/questions/generate', $payload);
    }

    public function analyzePerformance($studentIdOrPayload, array $history = []): array {
        if (is_array($studentIdOrPayload)) {
            $payload = $studentIdOrPayload;
        } else {
            $payload = [
                'student_id' => (int)$studentIdOrPayload,
                'history' => $history
            ];
        }
        return $this->request('POST', '/api/v1/performance/analyze', $payload);
    }

    public function analyzeStudentPerformance($studentIdOrPayload, array $history = []): array {
        return $this->analyzePerformance($studentIdOrPayload, $history);
    }

    public function predictQuestionDifficulty($questionIdOrPayload, array $attempts = []): array {
        if (is_array($questionIdOrPayload)) {
            $payload = $questionIdOrPayload;
        } else {
            $payload = [
                'question_id' => (int)$questionIdOrPayload,
                'attempts' => $attempts
            ];
        }
        return $this->request('POST', '/api/v1/ml/question-difficulty', $payload);
    }

    // --- Phase G: RAG Methods ---

    public function ingestDocument(string $filePath, int $docId, string $filename, string $subject = 'General', string $topic = 'General'): array {
        $payload = [
            'file_path' => $filePath,
            'document_id' => $docId,
            'filename' => $filename,
            'subject' => $subject,
            'topic' => $topic
        ];
        return $this->request('POST', '/api/v1/rag/ingest', $payload);
    }

    public function searchRAG(string $query, ?int $topK = null, ?string $subject = null, ?string $topic = null, ?float $threshold = null): array {
        $payload = ['query' => $query];
        if ($topK !== null) $payload['top_k'] = $topK;
        if ($subject !== null) $payload['subject'] = $subject;
        if ($topic !== null) $payload['topic'] = $topic;
        if ($threshold !== null) $payload['threshold'] = $threshold;
        return $this->request('POST', '/api/v1/rag/search', $payload);
    }

    public function askRAG(string $question, ?string $subject = null, ?string $topic = null, ?int $topK = null, ?float $threshold = null): array {
        $payload = ['question' => $question];
        if ($subject !== null) $payload['subject'] = $subject;
        if ($topic !== null) $payload['topic'] = $topic;
        if ($topK !== null) $payload['top_k'] = $topK;
        if ($threshold !== null) $payload['threshold'] = $threshold;
        return $this->request('POST', '/api/v1/rag/ask', $payload);
    }

    public function deleteRAGDocument(int $docId): array {
        return $this->request('DELETE', "/api/v1/rag/document/{$docId}");
    }

    // --- Phase H: Personalized Recommendations Methods ---

    public function getLearningProfile(int $studentId, array $history): array {
        $payload = [
            'student_id' => $studentId,
            'history' => $history
        ];
        return $this->request('POST', '/api/v1/recommendations/profile', $payload);
    }

    public function getPersonalizedStudyPlan(int $studentId, array $history): array {
        $payload = [
            'student_id' => $studentId,
            'history' => $history
        ];
        return $this->request('POST', '/api/v1/recommendations/plan', $payload);
    }

    public function generateTargetedPractice(string $subject, string $topic, string $difficulty = 'medium', int $count = 5): array {
        $payload = [
            'subject' => $subject,
            'topic' => $topic,
            'difficulty' => strtolower($difficulty),
            'number_of_questions' => $count
        ];
        return $this->request('POST', '/api/v1/recommendations/practice-questions', $payload);
    }

    private function request(string $method, string $endpoint, ?array $payload = null, ?int $overrideTimeout = null): array {
        $endpointClean = '/' . ltrim($endpoint, '/');
        $url = $this->baseUrl . $endpointClean;
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey
        ];

        $timeoutToUse = $overrideTimeout ?? $this->timeout;

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutToUse);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'online' => false,
                'status' => $httpCode,
                'error' => "AI Service connection error ({$curlErrno}): {$curlError}",
                'message' => "AI Service connection error ({$curlErrno}): {$curlError}",
                'code' => $curlErrno
            ];
        }

        $decoded = json_decode($response, true) ?? [];
        $isSuccess = ($httpCode >= 200 && $httpCode < 300);

        return [
            'success' => $isSuccess,
            'online' => true,
            'status' => $isSuccess ? 'success' : 'error',
            'http_code' => $httpCode,
            'data' => $decoded,
            'message' => $decoded['detail'] ?? ($isSuccess ? 'Success' : "HTTP Error {$httpCode}"),
            'raw' => $decoded
        ];
    }
}
