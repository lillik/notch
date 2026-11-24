CREATE TABLE links (
    code       text PRIMARY KEY,
    long_url   text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz
);

CREATE TABLE key_counter (
    id         integer PRIMARY KEY CHECK (id = 1),
    next_value bigint NOT NULL
);

INSERT INTO key_counter (id, next_value) VALUES (1, 0);

CREATE TABLE click_counts (
    code text PRIMARY KEY,
    hits bigint NOT NULL
);
