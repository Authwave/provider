select subject, htmlContent from EmailTemplate
where applicationId = :applicationId and name = :name
and (deploymentId = :deploymentId or deploymentId is null)
order by case when deploymentId is null then 1 else 0 end
limit 1
