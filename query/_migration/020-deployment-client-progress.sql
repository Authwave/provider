alter table ApplicationDeployment
    add clientConnected boolean not null default false,
    add userLoggedIn boolean not null default false;
