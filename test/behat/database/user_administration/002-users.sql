insert into User (id, applicationDeploymentId, email, hash, createdAt, clientNotificationStatus) values
('alice', 'managed-development', 'alice@example.test', null, '2026-01-01 12:00:00', 503),
('bob', 'managed-development', 'bob@example.test', null, '2026-02-01 12:00:00', null);
insert into Application (id, name) values ('unassigned', 'Another account');
insert into ApplicationDeployment (id, applicationId, title, secret, providerHost, clientHost, clientLoginPath) values
('unassigned-deployment', 'unassigned', 'Private', '0123456789abcdef0123456789abcdef', 'private.example.test', 'https://private.example.test', '/');
insert into User (id, applicationDeploymentId, email) values ('private-user', 'unassigned-deployment', 'private@example.test');
insert into Audit (id, userId, action, detail, createdAt) values
('login-alice', 'alice', 'LOGIN_COMPLETED', '{}', CURRENT_TIMESTAMP),
('failed-bob', 'bob', 'PASSWORD_LOGIN_FAILED', '{}', CURRENT_TIMESTAMP),
('failed-private', 'private-user', 'PASSWORD_LOGIN_FAILED', '{}', CURRENT_TIMESTAMP);

insert into ApplicationDeployment (id, applicationId, title, secret, providerHost, clientHost, clientLoginPath) values
('managed-staging', 'managed', 'Staging', '0123456789abcdef0123456789abcdef', 'staging.example.test', 'https://staging.example.test', '/');
insert into User (id, applicationDeploymentId, email) values ('staging-user', 'managed-staging', 'staging@example.test');
