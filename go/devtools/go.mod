module github.com/GeneralVini/ninfa/go/devtools

go 1.27.0

tool (
	github.com/securego/gosec/v2/cmd/gosec
	golang.org/x/vuln/cmd/govulncheck
	honnef.co/go/tools/cmd/staticcheck
)

require (
	github.com/securego/gosec/v2 v2.29.0
	golang.org/x/vuln v1.1.4
	honnef.co/go/tools v0.8.1
)
