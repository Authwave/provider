select
	`id`,
	`host`,
	`uri`,
	`apiKey`,
	`name`

from
	site

where
	host = ?
