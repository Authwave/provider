alter table User add createdAt datetime null;
alter table User add constraint user_deployment_email_unique unique (applicationDeploymentId, email);
