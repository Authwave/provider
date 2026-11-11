create index audit__user_action_created__index on Audit(userId, action, createdAt);
create index user__deployment_created__index on User(applicationDeploymentId, createdAt);
