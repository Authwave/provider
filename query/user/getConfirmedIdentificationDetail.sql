select
	identificationDetail

from
	user_identification

where
	userId = ?
and
	identificationType = ?
and
	confirmed is not null

order by
	confirmed desc

limit 1