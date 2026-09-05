<?php
/**
 * Phase G: AI RAG Study Assistant Test Suite
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_client.php';

echo "====================================================\n";
echo "    Phase G: AI RAG Study Assistant Test Suite      \n";
echo "====================================================\n\n";

$aiClient = new AiClient();

// [Test 1] Verify MySQL migration tables
echo "[Test 1] Verifying ai_documents and ai_document_chunks tables... ";
$res1 = $conn->query("SHOW TABLES LIKE 'ai_documents'");
$res2 = $conn->query("SHOW TABLES LIKE 'ai_document_chunks'");

if ($res1->num_rows > 0 && $res2->num_rows > 0) {
    echo "PASSED (Database tables exist)\n";
} else {
    echo "FAILED (Migration tables missing)\n";
    exit(1);
}

// [Test 2] Create temporary file & insert pending document record
echo "[Test 2] Ingesting test document into RAG Pipeline... ";
$tmp_dir = __DIR__ . '/../uploads/course_materials/';
if (!file_exists($tmp_dir)) {
    mkdir($tmp_dir, 0755, true);
}

$tmp_file = $tmp_dir . 'test_data_structures.txt';
file_put_contents($tmp_file, "Data Structures Unit 1: Binary Search Trees.\nA Binary Search Tree (BST) is a node-based binary tree data structure where the left subtree of a node contains only nodes with keys lesser than the node's key, and the right subtree contains only nodes with keys greater than the node's key.");

$stmt = $conn->prepare("INSERT INTO ai_documents (filename, original_name, file_path, file_size, subject, topic, status) VALUES ('test_data_structures.txt', 'Data_Structures_BST.txt', ?, ?, 'Computer Science', 'Binary Search Trees', 'pending')");
$fsize = filesize($tmp_file);
$stmt->bind_param("si", $tmp_file, $fsize);
$stmt->execute();
$doc_id = $stmt->insert_id;

$ingest_res = $aiClient->ingestDocument($tmp_file, $doc_id, 'Data_Structures_BST.txt', 'Computer Science', 'Binary Search Trees');

if ($ingest_res['status'] === 'success') {
    echo "PASSED (Ingested {$ingest_res['data']['total_chunks']} chunks into ChromaDB)\n";
} else {
    echo "FAILED (" . ($ingest_res['message'] ?? 'Ingestion failed') . ")\n";
    exit(1);
}

// [Test 3] Search RAG Vector Store
echo "[Test 3] Testing RAG Similarity Search API... ";
$search_res = $aiClient->searchRAG("What is a Binary Search Tree?", null, "Computer Science");

if ($search_res['status'] === 'success' && !empty($search_res['data']['chunks'])) {
    echo "PASSED (Retrieved matching chunks successfully)\n";
} else {
    echo "FAILED (Search returned no chunks)\n";
    exit(1);
}

// [Test 4] Grounded RAG Question Answering
echo "[Test 4] Testing Grounded RAG Answer Generation & Source Citations... ";
$ask_res = $aiClient->askRAG("Explain Binary Search Tree properties.", "Computer Science");

if ($ask_res['status'] === 'success' && $ask_res['data']['has_sufficient_context'] === true) {
    $sources = $ask_res['data']['sources'];
    if (!empty($sources) && $sources[0]['filename'] === 'Data_Structures_BST.txt') {
        echo "PASSED (Grounded answer generated with verified source citations)\n";
    } else {
        echo "FAILED (Citations missing or incorrect)\n";
        exit(1);
    }
} else {
    echo "FAILED (RAG ask query failed)\n";
    exit(1);
}

// [Test 5] Document Deletion & Vector Cleanup
echo "[Test 5] Testing Document Deletion & Index Consistency Cleanup... ";
$del_res = $aiClient->deleteRAGDocument($doc_id);

$del_db = $conn->prepare("DELETE FROM ai_documents WHERE id = ?");
$del_db->bind_param("i", $doc_id);
$del_db->execute();

if (file_exists($tmp_file)) {
    @unlink($tmp_file);
}

if ($del_res['status'] === 'success') {
    echo "PASSED (Deleted vectors and application metadata cleanly)\n\n";
} else {
    echo "FAILED (Deletion failed)\n\n";
    exit(1);
}

echo "----------------------------------------------------\n";
echo "Summary: 5 Passed, 0 Failed\n";
echo "====================================================\n";
