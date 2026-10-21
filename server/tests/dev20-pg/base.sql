CREATE TABLE public.eventlog (
    rowid bigserial PRIMARY KEY,
    ts bigint NOT NULL,
    gamets numeric NOT NULL,
    type text NOT NULL,
    data text NOT NULL,
    sess text NOT NULL,
    localts bigint NOT NULL,
    people text NOT NULL,
    location text NOT NULL,
    party text NOT NULL
);
CREATE TABLE public.core_npc_master (
    id bigint PRIMARY KEY,
    npc_name text NOT NULL,
    refid text NOT NULL,
    profile_id bigint NOT NULL
);
CREATE TABLE public.core_player (
    id text PRIMARY KEY,
    value text NOT NULL
);
CREATE TABLE public.game_plugins (
    plugin_name text NOT NULL,
    formid_prefix text NOT NULL
);
CREATE TABLE public.books (
    ts bigint,
    gamets numeric,
    content text,
    sess text,
    localts bigint,
    title text
);
CREATE TABLE public.responselog (
    localts bigint,
    sent integer,
    actor text,
    text text,
    action text,
    tag text
);

CREATE SCHEMA chim_meta;
CREATE TABLE chim_meta.settings (
    key text PRIMARY KEY,
    value text
);
CREATE TABLE chim_meta.playthrough_profiles (
    id bigserial PRIMARY KEY,
    name text NOT NULL,
    schema_name text NOT NULL,
    storage_type text NOT NULL,
    is_active boolean NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_gamets bigint NOT NULL DEFAULT 100,
    size_bytes bigint NOT NULL DEFAULT 0,
    player_name text NOT NULL DEFAULT 'Dragonborn'
);
CREATE SCHEMA chim_profile_dev20;
INSERT INTO chim_meta.playthrough_profiles(id,name,schema_name,storage_type,is_active,last_gamets,player_name)
VALUES (1,'Synthetic dev20 profile','chim_profile_dev20','schema',TRUE,100,'Dragonborn');
INSERT INTO chim_meta.settings(key,value) VALUES
    ('PLAYTHROUGH_HOME_REVISION','0'),
    ('PLAYTHROUGH_SESSION','{}');

INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES
    (100,100,'inputtext','dev20 synthetic fixture seed','',100,'','',''),
    (100,100,'info','Ordinary synthetic telemetry','pending',100,'','','');
INSERT INTO public.core_player(id,value) VALUES
    ('playthrough_id','0123456789abcdef0123456789abcdef'),
    ('player_name','Dragonborn');
INSERT INTO public.core_npc_master(id,npc_name,refid,profile_id) VALUES
    (900001,'Camilla','00013488',1),
    (900002,'Jarl Balgruuf','0001A677',1),
    (900003,'Lydia','000A2C94',1),
    (900004,'Rolemaster','00000010',1);
INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES ('Skyrim.esm','00');

GRANT USAGE, CREATE ON SCHEMA public, chim_meta, chim_profile_dev20 TO dwemer;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public, chim_meta, chim_profile_dev20 TO dwemer;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public, chim_meta, chim_profile_dev20 TO dwemer;
