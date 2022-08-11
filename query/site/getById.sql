select
	`id`,
	`host`,
	`uri`,
	`apiKey`,
	`name`

from
	site

where
	id = ?

limit 1
