insert into Application (id, name, emailSendFrom) values
('provider', 'Authwave provider', 'sender@example.test');
insert into ApplicationDeployment (id, applicationId, title, secret, providerHost, clientHost, clientLoginPath) values (
	'provider', 'provider', 'Authwave', '0123456789abcdef0123456789abcdef',
	'fixture.invalid', 'https://client.example.test', '/login/'
);
insert into User values ('administrator', 'provider', 'admin@example.test', null);
