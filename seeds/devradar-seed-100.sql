-- ١٠٠ منشور تجريبي بتنوّع مقصود، لاختبار خط الأنابيب على حجم أكبر.
-- المعرّفات 91001-91100 حتى يسهل حذفها.
BEGIN;

INSERT INTO authors (id, x_author_id, username, display_name, followers_count, created_at, updated_at)
SELECT 91000+g, (910000000+g)::text, 'dev'||g, 'Dev '||g, (g*137)%20000, now(), now()
FROM generate_series(1,20) g
ON CONFLICT (id) DO NOTHING;

INSERT INTO tweets (id, x_tweet_id, author_id, text, normalized_text, lang, posted_at,
  primary_url, canonical_url, status, like_count, repost_count, reply_count, quote_count,
  raw_payload, created_at, updated_at)
SELECT
  91000+g,
  (910000000000+g)::text,
  91000 + (g % 20) + 1,
  CASE g % 5
    WHEN 0 THEN 'Just launched '||t.nm||', an open source '||t.kind||' built with '||t.tech||'. github.com/dev'||g||'/'||lower(t.nm)
    WHEN 1 THEN 'Shipped '||t.nm||' — a '||t.kind||' written in '||t.tech||'. github.com/dev'||g||'/'||lower(t.nm)
    WHEN 2 THEN 'Released '||t.nm||' v1.0, my '||t.kind||' in '||t.tech||'. github.com/dev'||g||'/'||lower(t.nm)
    WHEN 3 THEN 'We are hiring a senior '||t.tech||' engineer. Remote, great benefits.'
    ELSE 'Thoughts on '||t.tech||' after a year in production. Long thread.'
  END,
  CASE g % 5
    WHEN 0 THEN 'just launched '||lower(t.nm)||' an open source '||t.kind||' built with '||lower(t.tech)
    WHEN 1 THEN 'shipped '||lower(t.nm)||' a '||t.kind||' written in '||lower(t.tech)
    WHEN 2 THEN 'released '||lower(t.nm)||' v1 0 my '||t.kind||' in '||lower(t.tech)
    WHEN 3 THEN 'we are hiring a senior '||lower(t.tech)||' engineer remote great benefits'
    ELSE 'thoughts on '||lower(t.tech)||' after a year in production long thread'
  END,
  CASE WHEN g % 11 = 0 THEN 'ar' ELSE 'en' END,
  now() - ((g % 7)||' days')::interval - ((g*13 % 24)||' hours')::interval,
  -- المنشوران 3 و 4 بلا رابط عمداً: يجب أن يرفضهما المرشّح بـ no-link
  CASE WHEN g % 5 IN (3,4) THEN NULL ELSE 'https://github.com/dev'||g||'/'||lower(t.nm) END,
  CASE WHEN g % 5 IN (3,4) THEN NULL ELSE 'https://github.com/dev'||g||'/'||lower(t.nm) END,
  'raw',
  (g*37) % 3000, (g*11) % 400, (g*7) % 120, (g*3) % 40,
  '{}'::jsonb, now(), now()
FROM generate_series(1,100) g
CROSS JOIN LATERAL (SELECT
  (ARRAY['Pgflow','Tinylog','Ratehawk','Zephyr','Quillbase','Nimbus','Corvid','Mesabox',
         'Driftwood','Sablecache'])[1+(g%10)] AS nm,
  (ARRAY['CLI tool','API gateway','database client','build tool','monitoring agent',
         'static analyser','job queue','feature flag service'])[1+(g%8)] AS kind,
  (ARRAY['Rust','Go','TypeScript','Python','Elixir','Zig'])[1+(g%6)] AS tech
) t
ON CONFLICT (id) DO NOTHING;

COMMIT;

SELECT 'أُدخل: '||count(*)||' منشور' FROM tweets WHERE id BETWEEN 91001 AND 91100;
SELECT 'بلا رابط (يُتوقع رفضها): '||count(*) FROM tweets WHERE id BETWEEN 91001 AND 91100 AND primary_url IS NULL;
SELECT 'عربية: '||count(*) FROM tweets WHERE id BETWEEN 91001 AND 91100 AND lang='ar';
