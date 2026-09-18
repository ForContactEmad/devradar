#!/bin/bash
# ---------------------------------------------------------------------------
# Schema behaviour verification.
#
# Each test runs in its own transaction and rolls back, so the database is
# left empty. No seed data is committed.
#
# Companion to tests/Integration/DatabaseSchemaTest.php, which is the real
# Pest suite. This shell version exists only because Composer could not reach
# Packagist in the authoring environment.
# ---------------------------------------------------------------------------

PSQL="psql -h /tmp/pgrun -p 5433 -d devradar -q -t -v ON_ERROR_STOP=1"
PASS=0; FAIL=0

# Minimal fixture inserted inside every test transaction.
FIXTURE="
INSERT INTO authors (id, x_author_id, username, created_at, updated_at)
  VALUES (1,'900001','alice',now(),now());
INSERT INTO search_queries (id, name, version, family, expression, created_at, updated_at)
  VALUES (1,'family-a',1,'A','test expression',now(),now());
INSERT INTO search_runs (id, search_query_id, created_at, updated_at)
  VALUES (1,1,now(),now());
INSERT INTO tweets (id, x_tweet_id, author_id, search_run_id, text, posted_at, raw_payload, created_at, updated_at)
  VALUES (1,'100001',1,1,'just open sourced our cli tool',now(),'{}'::jsonb,now(),now());
SELECT setval('authors_id_seq',        (SELECT max(id) FROM authors));
SELECT setval('search_queries_id_seq', (SELECT max(id) FROM search_queries));
SELECT setval('search_runs_id_seq',    (SELECT max(id) FROM search_runs));
SELECT setval('tweets_id_seq',         (SELECT max(id) FROM tweets));
"

expect_ok() {   # label, sql
  if echo "BEGIN; $FIXTURE $2 ROLLBACK;" | $PSQL > /tmp/t.out 2>&1; then
    echo "  PASS  $1"; PASS=$((PASS+1))
  else
    echo "  FAIL  $1"; sed -n '1,2p' /tmp/t.out | sed 's/^/          /'; FAIL=$((FAIL+1))
  fi
}

expect_fail() { # label, sql, expected error fragment
  if echo "BEGIN; $FIXTURE $2 ROLLBACK;" | $PSQL > /tmp/t.out 2>&1; then
    echo "  FAIL  $1  (statement was accepted but should have been rejected)"; FAIL=$((FAIL+1))
  else
    if grep -qi "$3" /tmp/t.out; then
      echo "  PASS  $1"; PASS=$((PASS+1))
    else
      echo "  FAIL  $1  (rejected, but not for the expected reason)"
      sed -n '1,2p' /tmp/t.out | sed 's/^/          /'; FAIL=$((FAIL+1))
    fi
  fi
}

echo "=== DEDUPLICATION ==="
expect_fail "same x_tweet_id cannot be stored twice" \
  "INSERT INTO tweets (x_tweet_id,author_id,text,posted_at,raw_payload,created_at,updated_at)
   VALUES ('100001',1,'dup',now(),'{}'::jsonb,now(),now());" \
  "tweets_x_tweet_id_unique"

expect_ok "different posts sharing a url_hash are both stored (collapse happens later)" \
  "UPDATE tweets SET url_hash=repeat('a',64) WHERE id=1;
   INSERT INTO tweets (x_tweet_id,author_id,text,posted_at,url_hash,raw_payload,created_at,updated_at)
   VALUES ('100002',1,'same project',now(),repeat('a',64),'{}'::jsonb,now(),now());"

expect_ok "duplicate group links to a surviving row" \
  "INSERT INTO tweets (id,x_tweet_id,author_id,text,posted_at,duplicate_of_tweet_id,duplicate_match_level,raw_payload,created_at,updated_at)
   VALUES (2,'100002',1,'dup of 1',now(),1,'canonical_url','{}'::jsonb,now(),now());"

# Regression: these two cases silently failed for weeks because the schema
# gained this constraint and the script was never updated. Asserting it
# directly means the next such change fails loudly rather than quietly.
expect_fail "a duplicate link without a match level is rejected" \
  "INSERT INTO tweets (id,x_tweet_id,author_id,text,posted_at,duplicate_of_tweet_id,raw_payload,created_at,updated_at)
   VALUES (2,'100002',1,'dup',now(),1,'{}'::jsonb,now(),now());" \
  "tweets_duplicate_level_consistent"

expect_fail "a match level without a duplicate link is rejected" \
  "UPDATE tweets SET duplicate_match_level='tweet_id' WHERE id=1;" \
  "tweets_duplicate_level_consistent"

expect_fail "a tweet cannot be its own duplicate" \
  "UPDATE tweets SET duplicate_of_tweet_id=1, duplicate_match_level='tweet_id' WHERE id=1;" \
  "tweets_not_self_duplicate"

expect_fail "two projects cannot share a canonical url_hash" \
  "INSERT INTO tweets (id,x_tweet_id,author_id,text,posted_at,raw_payload,created_at,updated_at)
     VALUES (2,'100002',1,'b',now(),'{}'::jsonb,now(),now());
   INSERT INTO projects (primary_tweet_id,slug,name,category,primary_url,url_hash,discovered_at,created_at,updated_at)
     VALUES (1,'a','A','developer-tools','https://x',repeat('b',64),now(),now(),now());
   INSERT INTO projects (primary_tweet_id,slug,name,category,primary_url,url_hash,discovered_at,created_at,updated_at)
     VALUES (2,'b','B','developer-tools','https://y',repeat('b',64),now(),now(),now());" \
  "projects_url_hash_unique"

expect_ok "projects with no resolvable url are still insertable (null url_hash repeats)" \
  "INSERT INTO tweets (id,x_tweet_id,author_id,text,posted_at,raw_payload,created_at,updated_at)
     VALUES (2,'100002',1,'b',now(),'{}'::jsonb,now(),now());
   INSERT INTO projects (primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,'a','A','developer-tools','https://x',now(),now(),now());
   INSERT INTO projects (primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (2,'b','B','developer-tools','https://y',now(),now(),now());"

echo ""
echo "=== PIPELINE STATE CONSTRAINTS ==="
expect_fail "unknown pipeline status is rejected" \
  "UPDATE tweets SET status='banana' WHERE id=1;" "tweets_status_allowed"

expect_ok "every documented status is accepted" \
  "UPDATE tweets SET status='normalized' WHERE id=1;
   UPDATE tweets SET status='deduplicated' WHERE id=1;
   UPDATE tweets SET status='filtered' WHERE id=1;
   UPDATE tweets SET status='pending_classification' WHERE id=1;
   UPDATE tweets SET status='classified' WHERE id=1;
   UPDATE tweets SET status='published' WHERE id=1;
   UPDATE tweets SET status='aged_out' WHERE id=1;
   UPDATE tweets SET status='purged' WHERE id=1;"

expect_fail "a rejection must record a reason" \
  "UPDATE tweets SET status='rejected' WHERE id=1;" "tweets_rejected_has_reason"

expect_ok "a rejection with a reason is accepted" \
  "UPDATE tweets SET status='rejected', reject_reason='hiring' WHERE id=1;"

echo ""
echo "=== RE-ANALYSIS ==="
expect_ok "a tweet can hold many analyses across prompt versions" \
  "INSERT INTO ai_analyses (tweet_id,provider,model,prompt_version,is_launch,confidence,is_current,created_at,updated_at)
     VALUES (1,'anthropic','m','v1',true,0.80,false,now(),now());
   INSERT INTO ai_analyses (tweet_id,provider,model,prompt_version,is_launch,confidence,is_current,created_at,updated_at)
     VALUES (1,'anthropic','m','v2',true,0.91,true,now(),now());"

expect_fail "a tweet cannot have two current analyses" \
  "INSERT INTO ai_analyses (tweet_id,provider,model,prompt_version,is_current,created_at,updated_at)
     VALUES (1,'anthropic','m','v1',true,now(),now());
   INSERT INTO ai_analyses (tweet_id,provider,model,prompt_version,is_current,created_at,updated_at)
     VALUES (1,'anthropic','m','v2',true,now(),now());" \
  "ai_analyses_one_current_per_tweet"

expect_fail "confidence outside 0..1 is rejected" \
  "INSERT INTO ai_analyses (tweet_id,provider,model,prompt_version,confidence,created_at,updated_at)
     VALUES (1,'anthropic','m','v1',1.5,now(),now());" \
  "ai_analyses_confidence_range"

echo ""
echo "=== RELATIONSHIPS / DELETE BEHAVIOUR ==="
expect_ok "deleting an author cascades to tweets, analyses and projects" \
  "INSERT INTO ai_analyses (tweet_id,provider,model,prompt_version,created_at,updated_at)
     VALUES (1,'anthropic','m','v1',now(),now());
   INSERT INTO projects (primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,'a','A','developer-tools','https://x',now(),now(),now());
   DELETE FROM authors WHERE id=1;
   DO \$\$ BEGIN
     IF (SELECT count(*) FROM tweets) <> 0 THEN RAISE EXCEPTION 'tweets survived'; END IF;
     IF (SELECT count(*) FROM projects) <> 0 THEN RAISE EXCEPTION 'projects survived'; END IF;
     IF (SELECT count(*) FROM ai_analyses) <> 0 THEN RAISE EXCEPTION 'analyses survived'; END IF;
   END \$\$;"

expect_fail "a search query with recorded runs cannot be deleted" \
  "DELETE FROM search_queries WHERE id=1;" "search_runs_search_query_id_foreign"

expect_ok "deleting a run preserves the posts it discovered" \
  "DELETE FROM search_runs WHERE id=1;
   DO \$\$ BEGIN
     IF (SELECT count(*) FROM tweets) <> 1 THEN RAISE EXCEPTION 'tweet lost'; END IF;
     IF (SELECT search_run_id FROM tweets WHERE id=1) IS NOT NULL THEN RAISE EXCEPTION 'fk not nulled'; END IF;
   END \$\$;"

expect_ok "deleting a repository leaves its projects intact" \
  "INSERT INTO repositories (id,host,owner,name,url,created_at,updated_at)
     VALUES (1,'github','o','n','https://github.com/o/n',now(),now());
   INSERT INTO projects (id,primary_tweet_id,repository_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,1,1,'a','A','developer-tools','https://x',now(),now(),now());
   DELETE FROM repositories WHERE id=1;
   DO \$\$ BEGIN
     IF (SELECT count(*) FROM projects) <> 1 THEN RAISE EXCEPTION 'project lost'; END IF;
     IF (SELECT repository_id FROM projects WHERE id=1) IS NOT NULL THEN RAISE EXCEPTION 'fk not nulled'; END IF;
   END \$\$;"

echo ""
echo "=== HISTORICAL METRICS ==="
expect_ok "many snapshots per project are retained" \
  "INSERT INTO projects (id,primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,1,'a','A','developer-tools','https://x',now(),now(),now());
   INSERT INTO project_metrics (project_id,captured_at,like_count,score,created_at)
     VALUES (1,now()-interval '2 hours',10,1.5,now());
   INSERT INTO project_metrics (project_id,captured_at,like_count,score,created_at)
     VALUES (1,now()-interval '1 hour',40,2.8,now());"

expect_fail "a snapshot cannot be recorded twice for the same instant" \
  "INSERT INTO projects (id,primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,1,'a','A','developer-tools','https://x',now(),now(),now());
   INSERT INTO project_metrics (project_id,captured_at,created_at)
     VALUES (1,'2026-09-09 10:00:00+00',now());
   INSERT INTO project_metrics (project_id,captured_at,created_at)
     VALUES (1,'2026-09-09 10:00:00+00',now());" \
  "project_metrics_project_captured_unique"

echo ""
echo "=== CATEGORY / TECHNOLOGY FILTERING ==="
expect_fail "unknown category is rejected" \
  "INSERT INTO projects (primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,'a','A','crypto-nonsense','https://x',now(),now(),now());" \
  "projects_category_allowed"

expect_ok "technology tags attach and detach cleanly" \
  "INSERT INTO projects (id,primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,1,'a','A','developer-tools','https://x',now(),now(),now());
   INSERT INTO technologies (id,slug,name,created_at,updated_at) VALUES (1,'rust','Rust',now(),now());
   INSERT INTO project_technology (project_id,technology_id) VALUES (1,1);
   DELETE FROM technologies WHERE id=1;
   DO \$\$ BEGIN
     IF (SELECT count(*) FROM project_technology) <> 0 THEN RAISE EXCEPTION 'pivot orphaned'; END IF;
     IF (SELECT count(*) FROM projects) <> 1 THEN RAISE EXCEPTION 'project deleted by tag removal'; END IF;
   END \$\$;"

expect_fail "the same technology cannot be attached twice" \
  "INSERT INTO projects (id,primary_tweet_id,slug,name,category,primary_url,discovered_at,created_at,updated_at)
     VALUES (1,1,'a','A','developer-tools','https://x',now(),now(),now());
   INSERT INTO technologies (id,slug,name,created_at,updated_at) VALUES (1,'rust','Rust',now(),now());
   INSERT INTO project_technology (project_id,technology_id) VALUES (1,1);
   INSERT INTO project_technology (project_id,technology_id) VALUES (1,1);" \
  "project_technology_pkey"

echo ""
echo "=== LEDGER INTEGRITY ==="
expect_fail "a run cannot finish before it started" \
  "UPDATE search_runs SET finished_at=started_at-interval '1 hour' WHERE id=1;" \
  "search_runs_finished_after_started"

expect_fail "unknown run status is rejected" \
  "UPDATE search_runs SET status='exploded' WHERE id=1;" "search_runs_status_allowed"

expect_ok "defaults apply on insert" \
  "DO \$\$ BEGIN
     IF (SELECT status FROM tweets WHERE id=1) <> 'raw' THEN RAISE EXCEPTION 'tweet status default'; END IF;
     IF (SELECT like_count FROM tweets WHERE id=1) <> 0 THEN RAISE EXCEPTION 'like default'; END IF;
     IF (SELECT status FROM search_runs WHERE id=1) <> 'running' THEN RAISE EXCEPTION 'run status default'; END IF;
     IF (SELECT cost_usd FROM search_runs WHERE id=1) <> 0 THEN RAISE EXCEPTION 'cost default'; END IF;
     IF (SELECT is_active FROM search_queries WHERE id=1) IS NOT TRUE THEN RAISE EXCEPTION 'active default'; END IF;
   END \$\$;"

echo ""
echo "---------------------------------------------"
echo "  passed: $PASS    failed: $FAIL"
echo "---------------------------------------------"
[ "$FAIL" -eq 0 ]
