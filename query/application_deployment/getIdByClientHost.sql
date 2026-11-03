select id
from ApplicationDeployment
where clientHost = ?
order by id
limit 1
