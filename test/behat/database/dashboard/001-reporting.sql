insert into Application (id,name) values ('managed','My website'), ('private','Private application');
insert into UserAdmin values ('administrator','managed');
insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values
('development','managed','Development','0123456789abcdef0123456789abcdef','login.test','https://client.test','/'),
('production','managed','Production','0123456789abcdef0123456789abcdef','prod.test','https://client.test','/'),
('private','private','Private','0123456789abcdef0123456789abcdef','private.test','https://private.test','/');
insert into User (id,applicationDeploymentId,email,createdAt) values
('alice','development','alice@example.test',CURRENT_TIMESTAMP),
('bob','production','bob@example.test',CURRENT_TIMESTAMP),
('old','production','old@example.test',datetime('now','-31 days')),
('private','private','private@example.test',CURRENT_TIMESTAMP);
insert into Audit (id,userId,action,createdAt) values
('login-one','alice','LOGIN_COMPLETED',CURRENT_TIMESTAMP),
('login-two','alice','LOGIN_COMPLETED',CURRENT_TIMESTAMP),
('failed','alice','PASSWORD_LOGIN_FAILED',CURRENT_TIMESTAMP),
('code','alice','SECURITY_CODE_REQUESTED',CURRENT_TIMESTAMP),
('password','alice','PASSWORD_CHANGED',CURRENT_TIMESTAMP),
('private-login','private','LOGIN_COMPLETED',CURRENT_TIMESTAMP),
('old-login','old','LOGIN_COMPLETED',datetime('now','-31 days'));
