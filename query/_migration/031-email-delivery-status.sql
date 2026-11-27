alter table Email add deliveryFailedAt datetime null;
create index email_deployment_created on Email (deploymentId, createdAt, id);
