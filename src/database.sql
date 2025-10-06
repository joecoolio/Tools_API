drop table news;
drop table chat_message;
drop table chat_neighbor;
drop table chat;
drop table notification;
drop table loan;
drop table tool;
drop table tool_category;
drop table audit_log;
drop table token;
drop table friendship;
drop table neighbor;
drop INDEX neighbor_geoloc_idx;
drop index neighbor_search_idx;
drop function notify_new_chat_message;
drop function notify_new_tool;
drop function notify_new_neighbor;


-- Each person registered in the system
create table neighbor (
	id serial primary key,
	userid varchar(255) unique,
	name varchar(255),
	nickname varchar(255),
	password_hash varchar(255),
	created timestamptz,
	created_by_ip varchar(255),
	activated boolean default false,
	photo_link varchar(255),
	home_address varchar(255),
	home_address_point GEOGRAPHY(POINT, 4326),
	phone_number varchar(255),
	email varchar(255),
	tool_count integer default 0,
	chat_last_seen timestamptz, 
	search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english', name), 'A') ||
        setweight(to_tsvector('english', coalesce(nickname, '')), 'B') ||
        setweight(to_tsvector('english', home_address), 'B') ||
        setweight(to_tsvector('english', coalesce(email, '') || ' ' || coalesce(phone_number, '')), 'C')
    ) STORED
);
CREATE INDEX neighbor_geoloc_idx ON neighbor USING gist (home_address_point);
CREATE INDEX neighbor_search_idx ON neighbor USING GIN (search_vector);


-- One-way links between 2 neighbors representing a friendship
create table friendship (
	neighbor_id integer references neighbor(id),
	friend_id integer references neighbor(id),
	create_date timestamptz default now(),
	primary key (neighbor_id, friend_id)
);

-- Tokens for oauth2
create table token (
	id uuid primary key,
	created timestamptz,
	expire timestamptz,
	neighbor_id integer references neighbor(id) 
);

-- Audit of api calls
create table audit_log (
	userid varchar(255),
	api varchar(255),
	source_ip varchar(255),
	execution_date timestamptz default now(),
	exec_time_ms real
);

-- Categories of tools
create table tool_category (
	id serial primary key,
	name varchar(255),
	icon varchar(255)
);
insert into tool_category (name, icon) values ('Car Tools', 'fa-car-side');
insert into tool_category (name, icon) values ('Hand Tools', 'fa-hammer');
insert into tool_category (name, icon) values ('Power Tools', 'fa-plug-circle-bolt');
insert into tool_category (name, icon) values ('Plumbing', 'fa-toilet');
insert into tool_category (name, icon) values ('Farm Implement', 'fa-cow');
insert into tool_category (name, icon) values ('Yard Maintenance', 'fa-tree');
insert into tool_category (name, icon) values ('Home Maintenance', 'fa-home');

-- Individual tools that are available
create table tool (
	id serial primary key,
	owner_id integer references neighbor(id),
	short_name varchar(255), -- e.g. Floor Jack
	brand varchar(255), -- e.g. Power Torque Tools
	name varchar(255), -- Full product name e.g. 3-1/2 Ton Floor Jack - PT34795
	product_url varchar(4096), -- Where you can find it: https://www.oreillyauto.com/detail/c/power-torque-tools/power-torque-tools-3-1-2-ton-floor-jack/ptt0/pt34795
	replacement_cost numeric(8,2),
	category int references tool_category(id),
	photo_link varchar(255),
	search_terms text[],
	search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(brand, '') || ' ' || coalesce(short_name, '') || ' ' || coalesce(name, '')), 'A') ||
        setweight(array_to_tsvector(search_terms), 'B')
    ) STORED
);
CREATE INDEX tool_search_idx ON tool USING GIN (search_vector);

create table loan (
	id serial primary key,
	tool_id integer references tool(id), -- which tool is involved
	neighbor_id integer references neighbor(id), -- who requested the loan
	request_ts timestamptz default now(), -- when was the request
	message varchar(4096), -- note from requestor to owner_id
	status varchar(255) check (status in ('submitted', 'approved', 'denied', 'cancelled')) -- status
);

-- A notification message
create table notification (
	id serial primary key,
	to_neighbor integer references neighbor(id) not null, -- this neighbor this notification is for
	from_neighbor integer references neighbor(id), -- if the notification was sent from another neighbor (null for system messages or whatever)
	type varchar(255), -- type of notification:  friend_request, ...
	message varchar(4096), -- message included in the notification
	data varchar(4096), -- extra data (json) for this request
	created_ts timestamptz default now(), -- when the notification was created
	resolved boolean default false, -- has the message been resolved (e.g. friend request accepted)
	resolution_ts timestamptz -- when the resolution occurred
);

-- A chat between people, this is the parent
create table chat (
	id uuid DEFAULT gen_random_uuid() primary key,
	started_by integer references neighbor(id) not null, -- who originally initiated the chat
	started_ts timestamptz default now() -- when it was initiated
);

-- The people involved in a chat
create table chat_neighbor (
	chat_id uuid references chat(id) not null,
	neighbor_id integer references neighbor(id) not null
);

-- The messages in a chat
create table chat_message (
	id uuid DEFAULT gen_random_uuid() primary key,
	chat_id uuid references chat(id) not null,
	from_neighbor integer references neighbor(id), -- sender
	send_ts timestamptz default now(), -- when it was sent
	message text, -- the text of the message
	read_by integer[] default '{}' -- array of neighbor IDs that have marked this read
);

CREATE OR REPLACE FUNCTION notify_new_chat_message()
RETURNS trigger AS $$
DECLARE
    parent_row RECORD;
	neighborIds jsonb;
BEGIN
    -- Fetch parent row using foreign key
    SELECT * INTO parent_row FROM chat WHERE id = NEW.chat_id;

	-- Fetch participants in the chat
	SELECT jsonb_agg(jsonb_build_object('neighbor_id', neighbor_id)) into neighborIds from chat_neighbor where chat_id = NEW.chat_id;

    -- Send notification with combined JSON
    PERFORM pg_notify(
		'new_chat_message_' || TG_TABLE_SCHEMA,
        json_build_object(
            'message', row_to_json(NEW),
			'neighbors', neighborIds,
            'chat', row_to_json(parent_row)
        )::text
    );

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_new_chat_message
AFTER INSERT ON chat_message
FOR EACH ROW EXECUTE FUNCTION notify_new_chat_message();


-- The messages in a chat
create table news (
	id serial primary key,
	type varchar not null, -- type of news: new_neighbor, new_tool
	occur_ts timestamptz default now(), -- when it happened
	occur_point GEOGRAPHY(POINT, 4326), -- where it happened
	neighbor_id integer references neighbor(id), -- if refering to a neighbor, which one
	tool_id integer references tool(id) -- if refering to a tool, which one
);

-- Trigger to create a news item after a new neighbor joins
CREATE OR REPLACE FUNCTION notify_new_neighbor()
RETURNS TRIGGER AS $$
BEGIN
  insert into news (type, occur_point, neighbor_id)
  values ('new_neighbor', new.home_address_point, new.id);
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_new_neighbor
AFTER INSERT ON neighbor
FOR EACH ROW
EXECUTE FUNCTION notify_new_neighbor();

-- Trigger to create a news item after a neighbor shares a tool
CREATE OR REPLACE FUNCTION notify_new_tool()
RETURNS TRIGGER AS $$
DECLARE
  owner_address_point GEOGRAPHY(POINT, 4326);
BEGIN
  SELECT home_address_point INTO owner_address_point
  FROM neighbor
  WHERE id = NEW.owner_id;
  
  insert into news (type, occur_point, neighbor_id, tool_id) 
  values ('new_tool', owner_address_point, new.owner_id, new.id);

  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_new_tool
AFTER INSERT ON tool
FOR EACH ROW
EXECUTE FUNCTION notify_new_tool();

-- End of database creation




-- Dev user
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA dev FROM dev;
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM dev;
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA tiger FROM dev;
REVOKE usage on schema dev from dev;
REVOKE usage on schema public from dev;
REVOKE usage on schema tiger from dev;
REVOKE usage on all sequences in schema dev from dev;
DROP ROLE dev;

CREATE USER dev WITH ENCRYPTED PASSWORD '1Zw23wVY6mJdGxxT7kgaRb7U';
ALTER ROLE dev SET search_path to dev, public, tiger;
GRANT USAGE ON SCHEMA dev TO dev;
GRANT USAGE ON SCHEMA public TO dev;
GRANT USAGE ON SCHEMA tiger TO dev;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA dev TO dev;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA dev TO dev;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO dev;
GRANT SELECT ON ALL TABLES IN SCHEMA tiger TO dev;

-- Prod user
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA prod FROM prod;
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM prod;
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA tiger FROM prod;
REVOKE usage on all sequences in schema prod from prod;
REVOKE usage on schema prod from prod;
REVOKE usage on schema public from prod;
REVOKE usage on schema tiger from prod;
DROP ROLE prod;

CREATE USER prod WITH ENCRYPTED PASSWORD '45O3W2o8U8cWg8oJcZorn3s3';
ALTER ROLE prod SET search_path to prod, public, tiger;
GRANT USAGE ON SCHEMA prod TO prod;
GRANT USAGE ON SCHEMA public TO prod;
GRANT USAGE ON SCHEMA tiger TO prod;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA prod TO prod;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA prod TO prod;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO prod;
GRANT SELECT ON ALL TABLES IN SCHEMA tiger TO prod;





delete from neighbor where id != 1;
insert into neighbor (id, userid, name, home_address) values (2, 'jill', 'Jill Billings', '9546 Hunting Ct. Matthews, NC 28105');
insert into neighbor (id, userid, name, home_address) values (3, 'phillip', 'phillip', '9559 Hunting Ct. Matthews, NC 28105');
insert into neighbor (id, userid, name, home_address) values (4, 'dean', 'dean', '9235 Sandpiper Dr, Charlotte, NC 28277');
insert into neighbor (id, userid, name, home_address) values (5, 'graham', 'graham', '120 Sardis Pointe Rd, Matthews, NC 28105');
insert into neighbor (id, userid, name, home_address) values (6, 'laura', 'laura', '225 Morning Dale Rd, Matthews, NC 28105');
insert into neighbor (id, userid, name, home_address) values (7, 'bubba', 'bubba', '9224 Hunting Ct, Matthews, NC 28105');
insert into neighbor (id, userid, name, home_address) values (8, 'paul', 'Paul Mc.', '9330 Joines Dr, Matthews, NC 28105');
insert into neighbor (id, userid, name, home_address) values (9, 'john', 'John Lennon', '9425 Lochmeade Ln, Matthews, NC 28105');

UPDATE neighbor
SET home_address_point = (
  SELECT ST_SetSRID(ST_SnapToGrid((g).geomout, 0.00001), 4326)
  FROM geocode(home_address, 1) AS g
)
WHERE home_address IS NOT NULL;

delete from friendship;
insert into friendship values (1,2); -- mike --> jill
insert into friendship values (1,3); -- mike --> phillip
insert into friendship values (2,4); -- jill --> dean
insert into friendship values (5,2); -- graham --> jill
insert into friendship values (6,1); -- laura --> mike
insert into friendship values (4,7); -- dean --> bubba
insert into friendship values (3,8); -- phillip --> paul
insert into friendship values (7,9); -- bubba --> john
insert into friendship values (7,3); -- bubba --> phillip

delete from tool_category;
insert into tool_category (name, icon) values ('Car Tools', 'fa-car-side');
insert into tool_category (name, icon) values ('Hand Tools', 'fa-hammer');
insert into tool_category (name, icon) values ('Power Tools', 'fa-plug-circle-bolt');
insert into tool_category (name, icon) values ('Plumbing', 'fa-toilet');
insert into tool_category (name, icon) values ('Farm Implement', 'fa-cow');
insert into tool_category (name, icon) values ('Yard Maintenance', 'fa-tree');
insert into tool_category (name, icon) values ('Home Maintenance', 'fa-home');

delete from tool;
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (4, 3, 'Drill', 'Bauer', '7.5 Amp, 1/2 in. Low-Speed Spade Handle Drill/Mixer', 'https://www.harborfreight.com/75-amp-12-in-low-speed-spade-handle-drillmixer-56179.html', 70);
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (3, 2, 'Notched Trowel', 'Marshalltown', '3/8-in x 1/4-in x 3/8-in Ground Steel Square Notch Ceramic Floor Trowel', 'https://www.lowes.com/pd/Marshalltown-11-in-Ground-Steel-Square-Notch-Ceramic-Floor-Trowel/5001934257', 12);
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (5, 2, 'Notched Trowel', 'Marshalltown', '3/8-in x 1/4-in x 3/8-in Ground Steel Square Notch Ceramic Floor Trowel', 'https://www.lowes.com/pd/Marshalltown-11-in-Ground-Steel-Square-Notch-Ceramic-Floor-Trowel/5001934257', 12);
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (7, 1, 'Jack Stands', 'Husky', '3-Ton Car Jack Stands', 'https://www.homedepot.com/p/Husky-3-Ton-Car-Jack-Stands-HPL4124-ZD/315635318', 50);
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (8, 1, 'Trailer Coupler Lock', 'Power Torque Towing', '1/2 Inch Diameter Universal Coupler Lock - PTW0030', 'https://www.oreillyauto.com/detail/c/power-torque-towing/power-torque-towing-1-2-inch-diameter-universal-coupler-lock/ptw0/ptw0030?q=universal+coupler', 40);
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (9, 1, 'Floor Jack', 'Power Torque Tools', '3-1/2 Ton Floor Jack - PT34795', 'https://www.oreillyauto.com/detail/c/power-torque-tools/power-torque-tools-3-1-2-ton-floor-jack/ptt0/pt34795', 275);
insert into tool(owner_id, category, short_name, brand, name, product_url, replacement_cost) values (4, 7, 'Plunger', 'Great Value', 'Deluxe Toilet Plunger with 16-in Ergonomic Plastic Handle', 'https://www.walmart.com/ip/Great-Value-Deluxe-Toilet-Plunger-with-16-in-Ergonomic-Plastic-Handle-1-Each/5964174776', 5);


-- List Mike's friends
SELECT
	n.id,
	n.name
FROM
	neighbor n
	inner join friendship f
		on n.id = f.friend_id
WHERE
	f.neighbor_id = 1
;

-- List Mike's friends recursive
WITH RECURSIVE friend_of_friend AS (
	SELECT friend_id, ARRAY[]::integer[] via, 1 depth
	FROM friendship
	WHERE neighbor_id = :neighborId
	
	UNION
	
	SELECT f.friend_id, fof.via || f.neighbor_id via, fof.depth + 1 depth
	FROM friendship f
	JOIN friend_of_friend fof
		ON f.neighbor_id = fof.friend_id
), numbered as (
	select
		fof.*,
		row_number() over (partition by fof.friend_id order by depth) rn
	from
		friend_of_friend fof
)
select
	friend_id,
	via,
	depth
from numbered
where rn = 1
;

-- List Mike's friends inside a radius recursive + show distance between them
WITH RECURSIVE friend_of_friend AS (
	SELECT friend_id, ARRAY[]::integer[] via, 1 depth
	FROM friendship
	WHERE neighbor_id = :neighborId
	
	UNION
	
	SELECT f.friend_id, fof.via || f.neighbor_id via, fof.depth + 1 depth
	FROM friendship f
	JOIN friend_of_friend fof
		ON f.neighbor_id = fof.friend_id
), numbered as (
	select
		fof.*,
		row_number() over (partition by fof.friend_id order by depth) rn
	from
		friend_of_friend fof
), me as (
	select id, home_address_point from neighbor where id = :neighborId
)
select
	nf.friend_id,
	nf.via,
	nf.depth,
	ST_Distance(me.home_address_point, f.home_address_point)
from
	numbered nf
	inner join neighbor f
		on nf.friend_id = f.id
	inner join me 
		on ST_DWithin(
			f.home_address_point,
			me.home_address_point,
			:radius_m --distance in meters
		)
where rn = 1
;


select * from neighbor;
delete from token;
select * from token;
select * from audit_log order by execution_date desc;

select * from token where id = uuid('e9bc6e70-efbc-4722-b44b-a088cb0944f7')


WITH RECURSIVE friend_of_friend AS (
	SELECT friend_id, ARRAY[]::integer[] via, 1 depth
	FROM friendship
	WHERE neighbor_id = 1
	
	UNION
	
	SELECT f.friend_id, fof.via || f.neighbor_id via, fof.depth + 1 depth
	FROM friendship f
	JOIN friend_of_friend fof
		ON f.neighbor_id = fof.friend_id
), numbered as (
	select
		fof.*,
		row_number() over (partition by fof.friend_id order by depth) rn
	from
		friend_of_friend fof
)
select
	friend_id,
	via,
	depth
from numbered
where rn = 1
;
SELECT
	n.id,
	n.name,
	fof.via via,
	fof.depth
FROM
	friend_of_friend fof
	inner join neighbor n
		on n.id = fof.friend_id
;


update neighbor set home_address_point = st_setsrid(st_makepoint(35.1194432,-80.7492582), 4326) where id = 1;
update neighbor set home_address_point = st_setsrid(st_makepoint(35.0806791,-80.8221924), 4326) where id = 4;

select n_friends.userid, st_distance(n_me.home_address, n_friends.home_address)
from
	neighbor n_me
	inner join neighbor n_friends
		on n_me.id = 1
		and n_friends.id != 1
		and n_friends.home_address is not null
;
SELECT ST_Distance(
    'SRID=4326;POINT(-118.4079 33.9434)'::geography, -- Los Angeles (LAX)
    'SRID=4326;POINT(2.5559 49.0083)'::geography    -- Paris (CDG)
);

-- Perform a complex search
SELECT 
    ts_rank(search_vector, query) as rank,
    neighbor.*
FROM 
    dev.neighbor,
    to_tsquery('english', 'jesus') query
WHERE search_vector @@ query
ORDER BY rank DESC;

SELECT 
    name,
    ts_rank(search_vector, query) as rank
FROM 
    dev.neighbor,
    phraseto_tsquery('english', 'jesus h. christ') query
WHERE search_vector @@ query
ORDER BY rank DESC;

