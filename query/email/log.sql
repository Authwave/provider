with deliveries as (
	select e.id, e.createdAt, e.scheduledToSendAt, e.sentAt, e.toEmail, e.subject, d.title,
		case when e.sentAt is not null then 'sent'
			when e.deliveryFailedAt is not null then 'error' else 'pending' end as status
	from Email e
	inner join ApplicationDeployment d on d.id = e.deploymentId
	inner join UserAdmin administrator on administrator.applicationId = d.applicationId
		and administrator.userId = :actorId
	where d.applicationId = :applicationId
	and e.createdAt >= :start and e.createdAt < :end
	and (:deploymentFilter = '' or d.id = :deploymentId)
	and (:recipientFilter = '' or instr(lower(e.toEmail), lower(:toEmail)) > 0)
	and (:subjectFilter = '' or instr(lower(e.subject), lower(:subject)) > 0)
)
select * from deliveries
where (:statusFilter = '' or status = :status)
order by createdAt desc, id desc
limit :limit offset :offset
