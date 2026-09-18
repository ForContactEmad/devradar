# DevRadar

> Discovers software projects launched on X in the last 7 days — classifies, ranks, and serves them as a short feed.

منصّة تكتشف المشاريع البرمجية المُطلَقة على منصّة X خلال الأيام السبعة الماضية، تحكم على جدّيتها، ترتّبها، وتعرضها كخلاصة موجزة.

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)
![Nuxt](https://img.shields.io/badge/Nuxt-4-00DC82)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED)

---

## Overview

Launch announcements get buried on X within hours. DevRadar answers one question — *what shipped this week* — by running a nine-stage pipeline over the platform's paid search API, filtering noise before it reaches an AI model, and ranking what survives.

إعلانات الإطلاق تُدفن على X خلال ساعات. يجيب المشروع عن سؤال واحد: **ما الذي صدر هذا الأسبوع؟**

## Stack

`PHP 8.3` · `Laravel 12` · `PostgreSQL 16` · `Redis 7` · `Nuxt 4` · `Vue 3` · `TypeScript` · `Docker Compose`

**APIs:** X API · Anthropic · GitHub

## Features

- Nine-stage pipeline with independent, individually scheduled stages
- Hard spend ceiling that halts collection rather than exceeding it
- Three-level deduplication: post ID → canonical URL → text fingerprint
- Free keyword pre-filter so the model only sees candidates
- Provider-agnostic AI layer (Anthropic, or any OpenAI-compatible endpoint)
- Six-component ranking score that explains itself in plain words
- Bilingual dashboard — English and Arabic with full RTL support
- Hexagonal architecture enforced by an automated dependency guard

## Getting started

```bash
cp .env.example .env     # set POSTGRES_PASSWORD and REDIS_PASSWORD
docker compose up
docker compose exec api php artisan migrate
```

- Dashboard — http://localhost:3000
- API — http://localhost:8000/api/v1/projects

## Status

Working end to end against live APIs. Not yet deployed to production.

---

**Emad Almuzaini** · Medina, Saudi Arabia
