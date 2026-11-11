-- Apply the revised length to databases which already ran migration 016.
alter table Application modify name varchar(128) not null;
