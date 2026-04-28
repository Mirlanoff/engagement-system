--
-- PostgreSQL database dump
--

\restrict 1Ve3YGgU5u6H0p2BTUFxdHdKwMPQymn9AG3YkBqs1B2DIPPhHdF35tZ97vN2Js5

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.13

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: ai_recommendations; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.ai_recommendations (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    generated_for uuid NOT NULL,
    type character varying(255) NOT NULL,
    content text NOT NULL,
    key_insights json DEFAULT '[]'::json NOT NULL,
    action_items json DEFAULT '[]'::json NOT NULL,
    session_avg_score numeric(5,2),
    input_data_summary json DEFAULT '{}'::json NOT NULL,
    model_used character varying(255) DEFAULT 'claude-sonnet-4-20250514'::character varying NOT NULL,
    tokens_used integer,
    is_read boolean DEFAULT false NOT NULL,
    read_at timestamp(0) without time zone,
    helpfulness_rating integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT ai_recommendations_type_check CHECK (((type)::text = ANY ((ARRAY['post_lesson_summary'::character varying, 'realtime_suggestion'::character varying, 'weekly_analysis'::character varying, 'student_insight'::character varying])::text[])))
);


ALTER TABLE public.ai_recommendations OWNER TO engage_user;

--
-- Name: alert_thresholds; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.alert_thresholds (
    id uuid NOT NULL,
    school_id uuid NOT NULL,
    classroom_id uuid,
    low_class_threshold numeric(5,2) DEFAULT '50'::numeric NOT NULL,
    low_student_threshold numeric(5,2) DEFAULT '30'::numeric NOT NULL,
    absent_minutes_threshold integer DEFAULT 3 NOT NULL,
    prolonged_low_minutes integer DEFAULT 10 NOT NULL,
    rapid_decline_delta numeric(5,2) DEFAULT '25'::numeric NOT NULL,
    rapid_decline_window_seconds integer DEFAULT 60 NOT NULL,
    notify_supervisor boolean DEFAULT true NOT NULL,
    notify_teacher boolean DEFAULT true NOT NULL,
    sound_alert boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.alert_thresholds OWNER TO engage_user;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache OWNER TO engage_user;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO engage_user;

--
-- Name: classroom_student; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.classroom_student (
    id uuid NOT NULL,
    classroom_id uuid NOT NULL,
    student_id uuid NOT NULL,
    seat_number integer,
    enrolled_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    left_at timestamp(0) without time zone
);


ALTER TABLE public.classroom_student OWNER TO engage_user;

--
-- Name: classrooms; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.classrooms (
    id uuid NOT NULL,
    school_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255),
    capacity integer DEFAULT 30 NOT NULL,
    camera_config json DEFAULT '[]'::json NOT NULL,
    detection_zones json DEFAULT '[]'::json NOT NULL,
    settings json DEFAULT '{}'::json NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.classrooms OWNER TO engage_user;

--
-- Name: engagement_aggregates; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.engagement_aggregates (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    classroom_id uuid NOT NULL,
    minute_at timestamp(0) without time zone NOT NULL,
    interval_minutes integer DEFAULT 1 NOT NULL,
    avg_score numeric(5,2) NOT NULL,
    min_score numeric(5,2) NOT NULL,
    max_score numeric(5,2) NOT NULL,
    std_dev numeric(5,2),
    students_detected integer NOT NULL,
    snapshots_count integer NOT NULL,
    high_engagement_count integer DEFAULT 0 NOT NULL,
    medium_engagement_count integer DEFAULT 0 NOT NULL,
    low_engagement_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.engagement_aggregates OWNER TO engage_user;

--
-- Name: engagement_alerts; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.engagement_alerts (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    classroom_id uuid NOT NULL,
    student_id uuid,
    type character varying(255) NOT NULL,
    severity character varying(255) DEFAULT 'warning'::character varying NOT NULL,
    trigger_score numeric(5,2),
    threshold_score numeric(5,2),
    message character varying(255) NOT NULL,
    context json DEFAULT '{}'::json NOT NULL,
    is_acknowledged boolean DEFAULT false NOT NULL,
    acknowledged_by uuid,
    acknowledged_at timestamp(0) without time zone,
    acknowledgement_note text,
    triggered_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT engagement_alerts_severity_check CHECK (((severity)::text = ANY ((ARRAY['info'::character varying, 'warning'::character varying, 'critical'::character varying])::text[]))),
    CONSTRAINT engagement_alerts_type_check CHECK (((type)::text = ANY ((ARRAY['low_class_engagement'::character varying, 'low_student_engagement'::character varying, 'student_absent'::character varying, 'rapid_decline'::character varying, 'prolonged_low'::character varying, 'anomaly_detected'::character varying])::text[])))
);


ALTER TABLE public.engagement_alerts OWNER TO engage_user;

--
-- Name: engagement_snapshots; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.engagement_snapshots (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    student_id uuid NOT NULL,
    classroom_id uuid NOT NULL,
    camera_id character varying(50) NOT NULL,
    captured_at timestamp(0) without time zone NOT NULL,
    engagement_score numeric(5,2) NOT NULL,
    gaze_score numeric(5,2),
    emotion_score numeric(5,2),
    head_pose_score numeric(5,2),
    presence_score numeric(5,2),
    emotion character varying(30),
    emotion_confidence numeric(4,3),
    gaze_yaw numeric(6,2),
    gaze_pitch numeric(6,2),
    head_yaw numeric(6,2),
    head_pitch numeric(6,2),
    head_roll numeric(6,2),
    face_detected boolean DEFAULT true NOT NULL,
    face_confidence numeric(4,3),
    face_bbox_x integer,
    face_bbox_y integer,
    face_bbox_w integer,
    face_bbox_h integer,
    processing_time_ms numeric(7,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.engagement_snapshots OWNER TO engage_user;

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO engage_user;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: engage_user
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO engage_user;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: engage_user
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO engage_user;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO engage_user;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: engage_user
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO engage_user;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: engage_user
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: lesson_sessions; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.lesson_sessions (
    id uuid NOT NULL,
    classroom_id uuid NOT NULL,
    teacher_id uuid,
    subject character varying(255),
    status character varying(255) DEFAULT 'scheduled'::character varying NOT NULL,
    started_at timestamp(0) without time zone,
    ended_at timestamp(0) without time zone,
    duration_minutes integer GENERATED ALWAYS AS ((EXTRACT(epoch FROM (ended_at - started_at)) / (60)::numeric)) STORED,
    avg_engagement_score numeric(5,2),
    min_engagement_score numeric(5,2),
    max_engagement_score numeric(5,2),
    total_snapshots integer DEFAULT 0 NOT NULL,
    students_count integer DEFAULT 0 NOT NULL,
    engagement_timeline json DEFAULT '[]'::json NOT NULL,
    meta json DEFAULT '{}'::json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT lesson_sessions_status_check CHECK (((status)::text = ANY ((ARRAY['scheduled'::character varying, 'active'::character varying, 'paused'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);


ALTER TABLE public.lesson_sessions OWNER TO engage_user;

--
-- Name: migrations; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO engage_user;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: engage_user
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO engage_user;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: engage_user
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO engage_user;

--
-- Name: schools; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.schools (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    address character varying(255),
    timezone character varying(255) DEFAULT 'Asia/Bishkek'::character varying NOT NULL,
    settings json DEFAULT '{}'::json NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.schools OWNER TO engage_user;

--
-- Name: sessions; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id uuid,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO engage_user;

--
-- Name: students; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.students (
    id uuid NOT NULL,
    school_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    student_code character varying(255),
    birth_date date,
    face_encoding_path character varying(255),
    consent_given boolean DEFAULT false NOT NULL,
    consent_given_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.students OWNER TO engage_user;

--
-- Name: users; Type: TABLE; Schema: public; Owner: engage_user
--

CREATE TABLE public.users (
    id uuid NOT NULL,
    school_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    role character varying(255) DEFAULT 'teacher'::character varying NOT NULL,
    avatar_path character varying(255),
    notification_preferences json DEFAULT '{}'::json NOT NULL,
    last_login_at timestamp(0) without time zone,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO engage_user;

--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Data for Name: ai_recommendations; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.ai_recommendations (id, session_id, generated_for, type, content, key_insights, action_items, session_avg_score, input_data_summary, model_used, tokens_used, is_read, read_at, helpfulness_rating, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: alert_thresholds; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.alert_thresholds (id, school_id, classroom_id, low_class_threshold, low_student_threshold, absent_minutes_threshold, prolonged_low_minutes, rapid_decline_delta, rapid_decline_window_seconds, notify_supervisor, notify_teacher, sound_alert, created_at, updated_at) FROM stdin;
633c9c18-f03a-4982-a943-528416cee67d	0bee384e-0ae5-46c5-b304-73cf64acab34	\N	50.00	30.00	3	10	25.00	60	t	t	f	2026-04-28 13:24:04	2026-04-28 13:24:04
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: classroom_student; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.classroom_student (id, classroom_id, student_id, seat_number, enrolled_at, left_at) FROM stdin;
a5704786-a91b-44d1-b1d6-c0e29bdc79a9	6b6c26cf-0916-4b56-9022-00566bcf202b	28844724-f504-4509-85e6-f866886d4c11	1	2026-04-28 13:24:04	\N
0d2866c1-05b3-4032-85e0-fe84c0c509e3	6b6c26cf-0916-4b56-9022-00566bcf202b	4d2fa53c-fc53-47ab-a22e-9f451b5d9646	2	2026-04-28 13:24:04	\N
912b39ed-df98-42ec-85a9-3aa0ec8f3ec5	6b6c26cf-0916-4b56-9022-00566bcf202b	b01544e1-c074-4042-aaf5-8136051bf644	3	2026-04-28 13:24:04	\N
31d4c148-2258-43b8-b6cd-7effda9fc911	6b6c26cf-0916-4b56-9022-00566bcf202b	e900e7d6-aca8-435b-b2e0-b01ff43e0c1d	4	2026-04-28 13:24:04	\N
89018701-554a-435d-b5af-b3b5a29248e7	6b6c26cf-0916-4b56-9022-00566bcf202b	f51e841f-ca6b-414d-8fa1-d9b579c1df4e	5	2026-04-28 13:24:04	\N
cfe4c7df-ffc3-45e5-a82d-757d3e57112e	6b6c26cf-0916-4b56-9022-00566bcf202b	175f3741-0569-43aa-80c6-0f2d85cd4aca	6	2026-04-28 13:24:04	\N
cbc83c4b-f859-4b68-8a63-511aa85ea230	6b6c26cf-0916-4b56-9022-00566bcf202b	ade44fbb-5ef5-4056-ae6f-b7681315dced	7	2026-04-28 13:24:04	\N
faf520ac-d776-4c48-a064-2c9a0dcafa33	6b6c26cf-0916-4b56-9022-00566bcf202b	1144f746-7079-40c0-a4d9-d64518b3a80c	8	2026-04-28 13:24:04	\N
5976040d-d60a-47aa-8fe5-13a7d58fef78	6b6c26cf-0916-4b56-9022-00566bcf202b	95e9e115-cac0-40ed-bbad-2a36f35a2fbd	9	2026-04-28 13:24:04	\N
dd4c94a9-b333-4c4e-ae78-e964bd0cf2c2	6b6c26cf-0916-4b56-9022-00566bcf202b	be50cded-ecf7-4b1a-89f7-8a17e666d6c2	10	2026-04-28 13:24:04	\N
721cb292-65d3-4dad-a146-8f89f1d16e53	6b6c26cf-0916-4b56-9022-00566bcf202b	189ef95e-463d-4f8a-8b94-69283e26340e	11	2026-04-28 13:24:04	\N
3cf923bd-1437-4d84-865c-f0293c74c844	6b6c26cf-0916-4b56-9022-00566bcf202b	6c19416c-4d80-4ee9-919b-ce749654e94b	12	2026-04-28 13:24:04	\N
a97109e0-3ff1-482a-adb5-e09f94d57023	6b6c26cf-0916-4b56-9022-00566bcf202b	bd5c1343-409a-4ac1-82b1-a3fe5ea7d789	13	2026-04-28 13:24:04	\N
152039e8-1ed1-4488-a2ea-3cd7deae41bd	6b6c26cf-0916-4b56-9022-00566bcf202b	7c3c10cd-c4a4-453a-a4f4-c19568a692d2	14	2026-04-28 13:24:04	\N
31b49c46-7263-4e08-bd40-fbb5d019d4a1	6b6c26cf-0916-4b56-9022-00566bcf202b	1e67e515-3152-44f9-b215-93dc5405eb69	15	2026-04-28 13:24:04	\N
864f1099-cbda-4d8b-bf78-0c3812e6caf6	6b6c26cf-0916-4b56-9022-00566bcf202b	5d3a90bc-bbdd-4fdc-8722-cc111a87dfbf	16	2026-04-28 13:24:04	\N
55de660b-2777-4dd9-9161-47d28d0179ea	6b6c26cf-0916-4b56-9022-00566bcf202b	11bf56ba-b3c6-4928-add0-81ada6b710ec	17	2026-04-28 13:24:04	\N
4cc82239-083d-4f13-9c20-874639ca7510	6b6c26cf-0916-4b56-9022-00566bcf202b	4230d096-1a90-4cec-a6ea-3fd5d899a19c	18	2026-04-28 13:24:04	\N
3cd027fb-c221-482d-a185-84914bdaf9c2	6b6c26cf-0916-4b56-9022-00566bcf202b	49f65fe1-4bde-43c9-a76d-61f6bf27d6d2	19	2026-04-28 13:24:04	\N
06600d3a-c4b0-4705-b910-40971b8d17d4	6b6c26cf-0916-4b56-9022-00566bcf202b	a258b533-6c7c-4b3f-9cc1-bb2428d58f3a	20	2026-04-28 13:24:04	\N
\.


--
-- Data for Name: classrooms; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.classrooms (id, school_id, name, code, capacity, camera_config, detection_zones, settings, is_active, created_at, updated_at, deleted_at) FROM stdin;
6b6c26cf-0916-4b56-9022-00566bcf202b	0bee384e-0ae5-46c5-b304-73cf64acab34	Класс 10А	10А	25	[{"id":"cam_front","rtsp_url":"rtsp:\\/\\/192.168.1.100:554\\/stream","position":"front","is_active":true}]	[]	{}	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
5fbf7534-7ffe-44c1-a427-b1e2198879eb	0bee384e-0ae5-46c5-b304-73cf64acab34	Класс 10Б	10Б	25	[{"id":"cam_front","rtsp_url":"rtsp:\\/\\/192.168.1.101:554\\/stream","position":"front","is_active":true}]	[]	{}	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
79e1ab06-4a13-42a8-9e17-935c4e9593b4	0bee384e-0ae5-46c5-b304-73cf64acab34	Класс 11А	11А	25	[{"id":"cam_front","rtsp_url":"rtsp:\\/\\/192.168.1.102:554\\/stream","position":"front","is_active":true}]	[]	{}	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
\.


--
-- Data for Name: engagement_aggregates; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.engagement_aggregates (id, session_id, classroom_id, minute_at, interval_minutes, avg_score, min_score, max_score, std_dev, students_detected, snapshots_count, high_engagement_count, medium_engagement_count, low_engagement_count, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: engagement_alerts; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.engagement_alerts (id, session_id, classroom_id, student_id, type, severity, trigger_score, threshold_score, message, context, is_acknowledged, acknowledged_by, acknowledged_at, acknowledgement_note, triggered_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: engagement_snapshots; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.engagement_snapshots (id, session_id, student_id, classroom_id, camera_id, captured_at, engagement_score, gaze_score, emotion_score, head_pose_score, presence_score, emotion, emotion_confidence, gaze_yaw, gaze_pitch, head_yaw, head_pitch, head_roll, face_detected, face_confidence, face_bbox_x, face_bbox_y, face_bbox_w, face_bbox_h, processing_time_ms, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: lesson_sessions; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.lesson_sessions (id, classroom_id, teacher_id, subject, status, started_at, ended_at, avg_engagement_score, min_engagement_score, max_engagement_score, total_snapshots, students_count, engagement_timeline, meta, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2024_01_01_000001_create_schools_table	1
5	2024_01_01_000003_create_classrooms_table	1
6	2024_01_01_000004_create_students_table	1
7	2024_01_01_000005_create_lesson_sessions_table	1
8	2024_01_01_000006_create_engagement_snapshots_table	1
9	2024_01_01_000007_create_alerts_recommendations_table	1
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: schools; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.schools (id, name, slug, address, timezone, settings, is_active, created_at, updated_at, deleted_at) FROM stdin;
0bee384e-0ae5-46c5-b304-73cf64acab34	Школа №1 г. Бишкек	school-1-bishkek	ул. Московская 123, Бишкек	Asia/Bishkek	{"language":"ru"}	t	2026-04-28 13:24:03	2026-04-28 13:24:03	\N
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
\.


--
-- Data for Name: students; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.students (id, school_id, name, student_code, birth_date, face_encoding_path, consent_given, consent_given_at, is_active, created_at, updated_at, deleted_at) FROM stdin;
28844724-f504-4509-85e6-f866886d4c11	0bee384e-0ae5-46c5-b304-73cf64acab34	Айбек Усупов	S0001	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
4d2fa53c-fc53-47ab-a22e-9f451b5d9646	0bee384e-0ae5-46c5-b304-73cf64acab34	Айгерим Токтосунова	S0002	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
b01544e1-c074-4042-aaf5-8136051bf644	0bee384e-0ae5-46c5-b304-73cf64acab34	Алибек Джумабаев	S0003	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
e900e7d6-aca8-435b-b2e0-b01ff43e0c1d	0bee384e-0ae5-46c5-b304-73cf64acab34	Анара Бекова	S0004	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
f51e841f-ca6b-414d-8fa1-d9b579c1df4e	0bee384e-0ae5-46c5-b304-73cf64acab34	Асель Исакова	S0005	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
175f3741-0569-43aa-80c6-0f2d85cd4aca	0bee384e-0ae5-46c5-b304-73cf64acab34	Бакыт Эшматов	S0006	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
ade44fbb-5ef5-4056-ae6f-b7681315dced	0bee384e-0ae5-46c5-b304-73cf64acab34	Гулназ Мамытова	S0007	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
1144f746-7079-40c0-a4d9-d64518b3a80c	0bee384e-0ae5-46c5-b304-73cf64acab34	Данияр Жунусов	S0008	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
95e9e115-cac0-40ed-bbad-2a36f35a2fbd	0bee384e-0ae5-46c5-b304-73cf64acab34	Диана Касымова	S0009	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
be50cded-ecf7-4b1a-89f7-8a17e666d6c2	0bee384e-0ae5-46c5-b304-73cf64acab34	Жаныбек Орунбеков	S0010	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
189ef95e-463d-4f8a-8b94-69283e26340e	0bee384e-0ae5-46c5-b304-73cf64acab34	Зарина Сыдыкова	S0011	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
6c19416c-4d80-4ee9-919b-ce749654e94b	0bee384e-0ae5-46c5-b304-73cf64acab34	Кайрат Абдиев	S0012	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
bd5c1343-409a-4ac1-82b1-a3fe5ea7d789	0bee384e-0ae5-46c5-b304-73cf64acab34	Ландыш Тоторова	S0013	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
7c3c10cd-c4a4-453a-a4f4-c19568a692d2	0bee384e-0ae5-46c5-b304-73cf64acab34	Мирлан Омуров	S0014	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
1e67e515-3152-44f9-b215-93dc5405eb69	0bee384e-0ae5-46c5-b304-73cf64acab34	Нурлан Асанов	S0015	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
5d3a90bc-bbdd-4fdc-8722-cc111a87dfbf	0bee384e-0ae5-46c5-b304-73cf64acab34	Перизат Жакыпова	S0016	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
11bf56ba-b3c6-4928-add0-81ada6b710ec	0bee384e-0ae5-46c5-b304-73cf64acab34	Рустем Байтиков	S0017	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
4230d096-1a90-4cec-a6ea-3fd5d899a19c	0bee384e-0ae5-46c5-b304-73cf64acab34	Салтанат Дуйшеева	S0018	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
49f65fe1-4bde-43c9-a76d-61f6bf27d6d2	0bee384e-0ae5-46c5-b304-73cf64acab34	Тилек Молдобаев	S0019	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
a258b533-6c7c-4b3f-9cc1-bb2428d58f3a	0bee384e-0ae5-46c5-b304-73cf64acab34	Умут Акматова	S0020	\N	\N	t	2026-04-28 13:24:04	t	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: engage_user
--

COPY public.users (id, school_id, name, email, password, role, avatar_path, notification_preferences, last_login_at, remember_token, created_at, updated_at, deleted_at) FROM stdin;
1154f6ac-4364-4d7e-a53d-59b0a2a97b23	0bee384e-0ae5-46c5-b304-73cf64acab34	Администратор	admin@school.kg	$2y$12$7L9RLzC/8XQcnsme2FOMSOzbqet8Ae8AiTYsqWbeTqxy4ft6xEbKm	admin	\N	{}	\N	\N	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
ed5069f9-f25d-481d-b1b6-048c8f76a32c	0bee384e-0ae5-46c5-b304-73cf64acab34	Супервайзер Айгуль	supervisor@school.kg	$2y$12$Eg5FnIpbJwllADG8alA5XeN7kzWWTmxV9a0c87hCOxEzIqPndHT9K	supervisor	\N	{}	\N	\N	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
a643e6cb-5223-4f77-a089-634ce3a69355	0bee384e-0ae5-46c5-b304-73cf64acab34	Учитель Акмат	teacher@school.kg	$2y$12$rh1ETJxnA7VVT5zmKw6rAeE6.ERJ3Gc1HfInOff0AYE/Rwe77ZmJW	teacher	\N	{}	\N	\N	2026-04-28 13:24:04	2026-04-28 13:24:04	\N
\.


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: engage_user
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: engage_user
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: engage_user
--

SELECT pg_catalog.setval('public.migrations_id_seq', 9, true);


--
-- Name: ai_recommendations ai_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_pkey PRIMARY KEY (id);


--
-- Name: alert_thresholds alert_thresholds_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.alert_thresholds
    ADD CONSTRAINT alert_thresholds_pkey PRIMARY KEY (id);


--
-- Name: alert_thresholds alert_thresholds_school_id_classroom_id_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.alert_thresholds
    ADD CONSTRAINT alert_thresholds_school_id_classroom_id_unique UNIQUE (school_id, classroom_id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: classroom_student classroom_student_classroom_id_student_id_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classroom_student
    ADD CONSTRAINT classroom_student_classroom_id_student_id_unique UNIQUE (classroom_id, student_id);


--
-- Name: classroom_student classroom_student_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classroom_student
    ADD CONSTRAINT classroom_student_pkey PRIMARY KEY (id);


--
-- Name: classrooms classrooms_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classrooms
    ADD CONSTRAINT classrooms_pkey PRIMARY KEY (id);


--
-- Name: classrooms classrooms_school_id_code_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classrooms
    ADD CONSTRAINT classrooms_school_id_code_unique UNIQUE (school_id, code);


--
-- Name: engagement_aggregates engagement_aggregates_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_aggregates
    ADD CONSTRAINT engagement_aggregates_pkey PRIMARY KEY (id);


--
-- Name: engagement_aggregates engagement_aggregates_session_id_minute_at_interval_minutes_uni; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_aggregates
    ADD CONSTRAINT engagement_aggregates_session_id_minute_at_interval_minutes_uni UNIQUE (session_id, minute_at, interval_minutes);


--
-- Name: engagement_alerts engagement_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_alerts
    ADD CONSTRAINT engagement_alerts_pkey PRIMARY KEY (id);


--
-- Name: engagement_snapshots engagement_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_snapshots
    ADD CONSTRAINT engagement_snapshots_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: lesson_sessions lesson_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.lesson_sessions
    ADD CONSTRAINT lesson_sessions_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: schools schools_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.schools
    ADD CONSTRAINT schools_pkey PRIMARY KEY (id);


--
-- Name: schools schools_slug_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.schools
    ADD CONSTRAINT schools_slug_unique UNIQUE (slug);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: students students_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_pkey PRIMARY KEY (id);


--
-- Name: students students_school_id_student_code_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_school_id_student_code_unique UNIQUE (school_id, student_code);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: ai_recommendations_generated_for_is_read_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX ai_recommendations_generated_for_is_read_index ON public.ai_recommendations USING btree (generated_for, is_read);


--
-- Name: ai_recommendations_session_id_type_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX ai_recommendations_session_id_type_index ON public.ai_recommendations USING btree (session_id, type);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: classrooms_school_id_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX classrooms_school_id_index ON public.classrooms USING btree (school_id);


--
-- Name: engagement_aggregates_classroom_id_minute_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_aggregates_classroom_id_minute_at_index ON public.engagement_aggregates USING btree (classroom_id, minute_at);


--
-- Name: engagement_alerts_classroom_id_is_acknowledged_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_alerts_classroom_id_is_acknowledged_index ON public.engagement_alerts USING btree (classroom_id, is_acknowledged);


--
-- Name: engagement_alerts_session_id_triggered_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_alerts_session_id_triggered_at_index ON public.engagement_alerts USING btree (session_id, triggered_at);


--
-- Name: engagement_alerts_type_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_alerts_type_index ON public.engagement_alerts USING btree (type);


--
-- Name: engagement_snapshots_captured_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_snapshots_captured_at_index ON public.engagement_snapshots USING btree (captured_at);


--
-- Name: engagement_snapshots_classroom_id_captured_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_snapshots_classroom_id_captured_at_index ON public.engagement_snapshots USING btree (classroom_id, captured_at);


--
-- Name: engagement_snapshots_engagement_score_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_snapshots_engagement_score_index ON public.engagement_snapshots USING btree (engagement_score);


--
-- Name: engagement_snapshots_session_id_captured_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_snapshots_session_id_captured_at_index ON public.engagement_snapshots USING btree (session_id, captured_at);


--
-- Name: engagement_snapshots_student_id_captured_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX engagement_snapshots_student_id_captured_at_index ON public.engagement_snapshots USING btree (student_id, captured_at);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: lesson_sessions_classroom_id_status_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX lesson_sessions_classroom_id_status_index ON public.lesson_sessions USING btree (classroom_id, status);


--
-- Name: lesson_sessions_started_at_ended_at_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX lesson_sessions_started_at_ended_at_index ON public.lesson_sessions USING btree (started_at, ended_at);


--
-- Name: lesson_sessions_teacher_id_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX lesson_sessions_teacher_id_index ON public.lesson_sessions USING btree (teacher_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: students_school_id_index; Type: INDEX; Schema: public; Owner: engage_user
--

CREATE INDEX students_school_id_index ON public.students USING btree (school_id);


--
-- Name: ai_recommendations ai_recommendations_generated_for_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_generated_for_foreign FOREIGN KEY (generated_for) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: ai_recommendations ai_recommendations_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.lesson_sessions(id) ON DELETE CASCADE;


--
-- Name: alert_thresholds alert_thresholds_classroom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.alert_thresholds
    ADD CONSTRAINT alert_thresholds_classroom_id_foreign FOREIGN KEY (classroom_id) REFERENCES public.classrooms(id) ON DELETE SET NULL;


--
-- Name: alert_thresholds alert_thresholds_school_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.alert_thresholds
    ADD CONSTRAINT alert_thresholds_school_id_foreign FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- Name: classroom_student classroom_student_classroom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classroom_student
    ADD CONSTRAINT classroom_student_classroom_id_foreign FOREIGN KEY (classroom_id) REFERENCES public.classrooms(id) ON DELETE CASCADE;


--
-- Name: classroom_student classroom_student_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classroom_student
    ADD CONSTRAINT classroom_student_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: classrooms classrooms_school_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.classrooms
    ADD CONSTRAINT classrooms_school_id_foreign FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- Name: engagement_aggregates engagement_aggregates_classroom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_aggregates
    ADD CONSTRAINT engagement_aggregates_classroom_id_foreign FOREIGN KEY (classroom_id) REFERENCES public.classrooms(id) ON DELETE CASCADE;


--
-- Name: engagement_aggregates engagement_aggregates_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_aggregates
    ADD CONSTRAINT engagement_aggregates_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.lesson_sessions(id) ON DELETE CASCADE;


--
-- Name: engagement_alerts engagement_alerts_acknowledged_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_alerts
    ADD CONSTRAINT engagement_alerts_acknowledged_by_foreign FOREIGN KEY (acknowledged_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: engagement_alerts engagement_alerts_classroom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_alerts
    ADD CONSTRAINT engagement_alerts_classroom_id_foreign FOREIGN KEY (classroom_id) REFERENCES public.classrooms(id) ON DELETE CASCADE;


--
-- Name: engagement_alerts engagement_alerts_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_alerts
    ADD CONSTRAINT engagement_alerts_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.lesson_sessions(id) ON DELETE CASCADE;


--
-- Name: engagement_alerts engagement_alerts_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_alerts
    ADD CONSTRAINT engagement_alerts_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE SET NULL;


--
-- Name: engagement_snapshots engagement_snapshots_classroom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_snapshots
    ADD CONSTRAINT engagement_snapshots_classroom_id_foreign FOREIGN KEY (classroom_id) REFERENCES public.classrooms(id) ON DELETE CASCADE;


--
-- Name: engagement_snapshots engagement_snapshots_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_snapshots
    ADD CONSTRAINT engagement_snapshots_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.lesson_sessions(id) ON DELETE CASCADE;


--
-- Name: engagement_snapshots engagement_snapshots_student_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.engagement_snapshots
    ADD CONSTRAINT engagement_snapshots_student_id_foreign FOREIGN KEY (student_id) REFERENCES public.students(id) ON DELETE CASCADE;


--
-- Name: lesson_sessions lesson_sessions_classroom_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.lesson_sessions
    ADD CONSTRAINT lesson_sessions_classroom_id_foreign FOREIGN KEY (classroom_id) REFERENCES public.classrooms(id) ON DELETE CASCADE;


--
-- Name: lesson_sessions lesson_sessions_teacher_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.lesson_sessions
    ADD CONSTRAINT lesson_sessions_teacher_id_foreign FOREIGN KEY (teacher_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: students students_school_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: engage_user
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_school_id_foreign FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict 1Ve3YGgU5u6H0p2BTUFxdHdKwMPQymn9AG3YkBqs1B2DIPPhHdF35tZ97vN2Js5

