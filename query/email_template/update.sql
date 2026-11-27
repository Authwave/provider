update EmailTemplate set subject = :subject, htmlContent = :htmlContent
where applicationId = :applicationId and scopeId = :scopeId and name = :name
