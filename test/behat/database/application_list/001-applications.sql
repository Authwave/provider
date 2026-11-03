insert into Application (id, name) values
('empty-app', 'No deployments'),
('production-app', 'Production website'),
('unassigned-app', 'Unassigned production'),
('foreign-app', 'Private application');
insert into UserAdmin values
('administrator', 'empty-app'),
('administrator', 'production-app'),
('administrator', 'unassigned-app');
insert into ApplicationDeployment values
('production-deployment', 'production-app', 'Production', '0123456789abcdef0123456789abcdef', 'login.example.test', 'https://www.example.test', '/login/'),
('staging-deployment', 'production-app', 'Staging', '0123456789abcdef0123456789abcdef', 'staging-login.example.test', 'https://staging.example.test', '/login/'),
('unassigned-deployment', 'unassigned-app', 'Development', '0123456789abcdef0123456789abcdef', 'unassigned-login.example.test', 'https://unassigned.example.test', '/login/');
update Application set productionApplicationDeploymentId = 'production-deployment' where id = 'production-app';
