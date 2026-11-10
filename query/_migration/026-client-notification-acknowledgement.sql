alter table User add clientNotificationAcknowledged boolean null;
update User set clientNotificationAcknowledged = (clientNotificationStatus = 204)
where clientNotificationStatus is not null;
