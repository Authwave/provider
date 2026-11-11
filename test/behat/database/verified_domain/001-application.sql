insert into Application (id, name) values ('managed', 'My website');
insert into UserAdmin values ('administrator', 'managed');
insert into ApplicationDeployment (id, applicationId, title, secret, providerHost, clientHost, clientLoginPath) values (
    'managed-development', 'managed', 'Development', '0123456789abcdef0123456789abcdef',
    'login.example.test', 'https://www.example.test', '/login/'
);
update ApplicationDeployment set domainVerified = 1 where id = 'managed-development';
