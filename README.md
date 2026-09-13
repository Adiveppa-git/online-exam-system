# AI-Powered Online Examination & Personalized Learning System

An intelligent full-stack Online Examination System enhanced with **Artificial Intelligence, Machine Learning, Retrieval-Augmented Generation (RAG), personalized learning, and adaptive recommendations**.

The platform supports Admin and Student workflows while using a separate **FastAPI AI microservice** for AI-powered capabilities.

---

## 🚀 Project Overview

The **AI-Powered Online Examination & Personalized Learning System** is a full-stack educational platform designed to go beyond traditional online examinations.

The system combines:

- Online examination management
- Automated AI question generation
- AI-generated question review and approval
- Machine-learning-based question difficulty estimation
- Student performance analytics
- Personalized learning recommendations
- Adaptive practice sessions
- Retrieval-Augmented Generation (RAG)
- Subject-specific AI study assistance
- Question-specific focused study notes
- AI-generated explanations
- Secure course-material management
- PostgreSQL + pgvector vector search
- FastAPI AI microservice
- PHP web application
- Supabase cloud infrastructure

The project demonstrates how conventional web applications can be extended into an **AI Engineering system with real AI/ML pipelines**.

---

## 🎯 Objectives

The main objectives of this project are:

1. Provide a complete online examination platform.
2. Automate question generation using AI.
3. Allow administrators to review and approve AI-generated questions.
4. Estimate question difficulty using Machine Learning.
5. Analyze student performance using deterministic analytics.
6. Generate personalized learning recommendations.
7. Provide adaptive practice based on student weaknesses.
8. Build a RAG-based AI Study Assistant.
9. Ensure AI responses remain restricted to the relevant subject.
10. Generate focused study notes for individual questions.
11. Store and retrieve course materials using vector search.
12. Demonstrate production-oriented AI microservice architecture.

---

# 🧠 AI Engineering Features

## 1. AI Question Generation

Administrators can generate examination questions using AI based on:

- Subject
- Topic
- Difficulty
- Question type
- Number of questions

Generated questions are not immediately inserted into the live question bank.

They first enter a **staging and review workflow**.

### Workflow

```text
Admin
  ↓
Enter subject/topic/difficulty
  ↓
AI Question Generator
  ↓
Generated Question
  ↓
Review
  ↓
Approve / Reject
  ↓
Approved Question Bank
