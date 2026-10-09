-- 001: roles, extensions, the acting member, and the directory mirror.
--
-- Sales CRM is an application from us beside the Business OS kernel (maludb-os-integration 0.9.0): the business's PROSPECTS,
-- RELATIONSHIPS AND DEALS — leads, accounts, contacts, deals through pipelines on a board, activities on a reminder calendar, email
-- logged against contacts (design docs/sales-crm-design.md). It owns the book; the kernel owns the DIRECTORY and the agents. The
-- identity tables below are a MIRROR of the kernel's directory with the kernel's ids -- written only from a hand-off token's claims
-- or the directory change feed, never generated here. There is no password and no account: a member signs in at the kernel's
-- launcher; a contact never signs in; an outside system holds a key (db/003) and never signs in at all.
--
-- THE ESTATE'S DATA MODEL (shared-schema.md; design §6.5): this file is the kernel contract as Consultant Tracking db/001 and
-- Knowledge db/001 carry it, copied verbatim (dc_ → crm_ on this application's own functions; the contract's app_* names stay,
-- because the sign-on kit and the MCP servers call them by those names); `members` APPENDS `is_agent` (Spaces' mirror exposes
-- it; here a generated column so it can never disagree with member_kind). Reused tables are created here, by this
-- application's own migrations, whichever siblings an installation holds.
--
-- Run in order as postgres on an empty database named <tenant>_sales_crm:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_sales_crm -f db/001_roles_and_identity.sql
-- The installer sets the three role passwords afterwards (ALTER ROLE ... PASSWORD).
BEGIN;

CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;         -- gen_random_bytes: a link's token and a key's raw form (minted in SQL and PHP)
CREATE EXTENSION IF NOT EXISTS unaccent;         -- the keyword index (db/009): words without their accents, Spaces' search_index verbatim

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sales_crm_rw') THEN
        CREATE ROLE sales_crm_rw LOGIN;                 -- PHP: the only writer
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sales_crm_records_ro') THEN
        CREATE ROLE sales_crm_records_ro LOGIN;         -- records MCP: mcp_* views and the read functions only
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sales_crm_activity_ro') THEN
        CREATE ROLE sales_crm_activity_ro LOGIN;        -- activity MCP: mcp_activity_log and names only
    END IF;
END$$;

GRANT USAGE ON SCHEMA public TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO sales_crm_rw;

-- ---------------------------------------------------------------------------------------------
-- The acting member. PHP sets it connection-wide from the session (or from a verified action token); the MCP
-- servers set it transaction-locally from the verified bearer. Unset = anonymous and every rule below denies.
-- (The token API — a key — runs in PHP as the writer with the KEY'S PERSON as the acting member: it sees exactly what
-- they see, db/005.)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION app_current_member_id() RETURNS bigint
    LANGUAGE sql STABLE AS $$
    SELECT NULLIF(current_setting('app.member_id', true), '')::bigint;
$$;

-- The design's name for the same thing (§3): one function, two names, so a worker reading the design finds it.
CREATE OR REPLACE FUNCTION crm_current_member_id() RETURNS bigint
    LANGUAGE sql STABLE AS $$ SELECT app_current_member_id(); $$;

CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The mirror (sign-on-and-directory.md §3-4; roles-and-rights.md §4). Columns are the directory's own facts as the
-- change feed carries them (os.directory/1), plus `roles`: the Sales CRM roles the kernel says the member holds.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE members (
    id              bigint PRIMARY KEY,                       -- the kernel's member id
    member_kind     text NOT NULL CHECK (member_kind IN ('human', 'agent')),
    display_name    text NOT NULL,
    email           citext,
    business_role   text NOT NULL CHECK (business_role IN ('super_admin', 'dept_admin', 'user')),
    is_external     boolean NOT NULL DEFAULT false,
    status          text NOT NULL CHECK (status IN ('active', 'inactive')),
    capability      text CHECK (capability IN ('read', 'write', 'admin')),  -- this application's grant; NULL = none
    roles           text[] NOT NULL DEFAULT '{}',              -- the Sales CRM roles held (claims.roles / feed access[].roles)
    job_title       text,
    phone           text,                                      -- the directory's; Sales CRM never texts it (K6 uses the kernel's verified phone)
    timezone        text NOT NULL DEFAULT 'UTC',
    directory_updated_at timestamptz,
    synced_at       timestamptz NOT NULL DEFAULT now(),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.5): "agent" always means an OS AI agent; derived, never written, so it cannot drift from member_kind
    is_agent        boolean GENERATED ALWAYS AS (member_kind = 'agent') STORED,
    -- appended (design §6.5): a seller's own words under every email they send ({{sender.signature}}); kept here, never in the kernel
    email_signature text
);
CREATE INDEX members_name_trgm_idx ON members USING gin (display_name gin_trgm_ops);
CREATE INDEX members_status_idx ON members (status, member_kind);
CREATE INDEX members_email_idx ON members (email);
CREATE TRIGGER members_touch BEFORE UPDATE ON members FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
COMMENT ON COLUMN members.roles IS 'The Sales CRM roles the kernel says this member holds. Written only from the kernel''s word (claims, feed access[]), like capability.';
COMMENT ON COLUMN members.is_agent IS 'Appended to the contract mirror (design §6.5): member_kind = agent, generated so it never disagrees.';

CREATE TABLE departments (
    id                 bigint PRIMARY KEY,                    -- the kernel's department id
    name               text NOT NULL,
    description        text,
    parent_id          bigint REFERENCES departments(id) ON DELETE SET NULL,
    manager_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    is_system          boolean NOT NULL DEFAULT false,
    system_key         text,                                  -- front_office | hr | accounting | audit | it
    archived_at        timestamptz,
    directory_updated_at timestamptz,
    synced_at          timestamptz NOT NULL DEFAULT now(),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX departments_parent_idx  ON departments (parent_id);
CREATE INDEX departments_manager_idx ON departments (manager_member_id);
CREATE TRIGGER departments_touch BEFORE UPDATE ON departments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE department_members (
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    department_id   bigint NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
    is_admin        boolean NOT NULL DEFAULT false,
    is_primary      boolean NOT NULL DEFAULT false,
    joined_at       timestamptz,
    left_at         timestamptz,                              -- dated, never erased (the feed's rule)
    synced_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, department_id)
);
CREATE INDEX department_members_dept_idx ON department_members (department_id) WHERE left_at IS NULL;

-- The change-feed checkpoint (one row), advanced under an advisory lock by bin/directory_sync.php.
CREATE TABLE directory_sync_state (
    id           smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    next_cursor  text,                                        -- the feed's `next`; NULL = never synced
    full_at      timestamptz,
    last_run_at  timestamptz,
    last_error   text,
    updated_at   timestamptz NOT NULL DEFAULT now()
);
INSERT INTO directory_sync_state (id) VALUES (1) ON CONFLICT DO NOTHING;

-- Hand-off tokens are single use: the nonce is kept until it expires (sign-on-and-directory.md §1).
CREATE TABLE sso_nonces (
    nonce       text PRIMARY KEY,
    member_id   bigint NOT NULL,
    expires_at  timestamptz NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sso_nonces_expires_idx ON sso_nonces (expires_at);

-- Every session the application opens, so the kernel's sign-out notice can end all of a member's at once (§2).
CREATE TABLE member_sessions (
    session_hash  text PRIMARY KEY,                           -- sha256 of the PHP session id
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    created_at    timestamptz NOT NULL DEFAULT now(),
    last_seen_at  timestamptz NOT NULL DEFAULT now(),
    ended_at      timestamptz,
    ended_by      text CHECK (ended_by IN ('member', 'kernel', 'expired', 'directory'))
);
CREATE INDEX member_sessions_member_idx ON member_sessions (member_id) WHERE ended_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- Who is asking. PL/pgSQL, one query each (the kernel's db/160 lesson). The mcp_* views (db/014) test these ONCE
-- per statement as scalar subqueries; PHP mirrors them in its gates. A caller with no mirror row is nobody.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION app_member_kind() RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text;
BEGIN
    SELECT member_kind INTO k FROM members WHERE id = app_current_member_id();
    RETURN k;
END$$;

-- Active in the directory AND admitted here (a live capability). An agent is admitted by its access grant, which the
-- kernel expresses as a capability too (or by first contact, db/014 mcp_admit_agent).
CREATE OR REPLACE FUNCTION app_is_active_member() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id()
                     AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

CREATE OR REPLACE FUNCTION app_is_super_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id() AND m.status = 'active'
                     AND m.member_kind = 'human' AND m.business_role = 'super_admin');
END$$;

-- Someone who works here: an active, non-external human with a capability.
CREATE OR REPLACE FUNCTION app_is_insider() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id() AND m.status = 'active'
                     AND m.member_kind = 'human' AND NOT m.is_external AND m.capability IS NOT NULL);
END$$;

CREATE OR REPLACE FUNCTION app_capability() RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE c text;
BEGIN
    SELECT capability INTO c FROM members WHERE id = app_current_member_id() AND status = 'active';
    RETURN c;
END$$;

-- The live departments the caller belongs to (a department is a TEAM here: filters, the leaderboard, a restriction).
CREATE OR REPLACE FUNCTION app_member_department_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT dm.department_id FROM department_members dm
          JOIN departments d ON d.id = dm.department_id AND d.archived_at IS NULL
         WHERE dm.member_id = app_current_member_id() AND dm.left_at IS NULL;
END$$;

-- The departments the caller administers: flagged admin of, or the named manager of (a directory fact).
CREATE OR REPLACE FUNCTION app_admin_department_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT dm.department_id FROM department_members dm
         WHERE dm.member_id = app_current_member_id() AND dm.is_admin AND dm.left_at IS NULL
        UNION
        SELECT d.id FROM departments d
         WHERE d.manager_member_id = app_current_member_id() AND d.archived_at IS NULL;
END$$;

GRANT EXECUTE ON FUNCTION app_current_member_id(), crm_current_member_id(), app_member_kind(), app_is_active_member(), app_is_super_admin(),
    app_is_insider(), app_capability(), app_member_department_ids(), app_admin_department_ids()
TO sales_crm_rw, sales_crm_records_ro, sales_crm_activity_ro;

-- PHP is the only writer of the mirror and the session tables; the read roles see none of it directly
-- (the mcp_* views in db/014 are their only window).
GRANT SELECT, INSERT, UPDATE, DELETE ON members, departments, department_members,
    directory_sync_state, sso_nonces, member_sessions TO sales_crm_rw;

COMMIT;
