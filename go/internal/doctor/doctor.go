// Package doctor performs read-only checks of the development environment used by Ninfa.
package doctor

import (
	"context"
	"errors"
	"os/exec"
	"strings"
	"time"
)

// Tool describes one executable that can participate in Ninfa development.
// probe is intentionally fixed by DefaultTools so repository or configuration data
// cannot select an arbitrary executable for the version checks.
type Tool struct {
	Name     string
	Command  string
	Required bool
	probe    func(context.Context) *exec.Cmd
}

// Result records whether a tool was located and could answer its version probe.
type Result struct {
	Tool    Tool
	Path    string
	Version string
	Err     error
}

// Checker runs bounded, read-only executable probes.
type Checker struct {
	Timeout time.Duration
}

// DefaultTools returns the initial Go-control-plane environment contract.
// PHP and Git remain required because the existing Ninfa implementation is PHP-first.
// Go is required for developing the new control plane. Other tools are informational here.
func DefaultTools() []Tool {
	return []Tool{
		{
			Name: "PHP", Command: "php", Required: true,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "php", "--version") },
		},
		{
			Name: "Git", Command: "git", Required: true,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "git", "--version") },
		},
		{
			Name: "Go", Command: "go", Required: true,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "go", "version") },
		},
		{
			Name: "Make", Command: "make", Required: true,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "make", "--version") },
		},
		{
			Name: "Composer", Command: "composer", Required: false,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "composer", "--version") },
		},
		{
			Name: "Semgrep", Command: "semgrep", Required: false,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "semgrep", "--version") },
		},
		{
			Name: "Staticcheck", Command: "staticcheck", Required: false,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "staticcheck", "-version") },
		},
		{
			Name: "gosec", Command: "gosec", Required: false,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "gosec", "-version") },
		},
		{
			Name: "govulncheck", Command: "govulncheck", Required: false,
			probe: func(ctx context.Context) *exec.Cmd { return exec.CommandContext(ctx, "govulncheck", "-version") },
		},
	}
}

// Check locates a tool and executes only the fixed probe associated with DefaultTools.
// No shell is involved and the probe is cancelled when the context or timeout expires.
func (c Checker) Check(ctx context.Context, tool Tool) Result {
	path, err := exec.LookPath(tool.Command)
	if err != nil {
		return Result{Tool: tool, Err: err}
	}
	if tool.probe == nil {
		return Result{Tool: tool, Path: path, Err: errors.New("tool has no fixed probe")}
	}

	timeout := c.Timeout
	if timeout <= 0 {
		timeout = 3 * time.Second
	}

	probeCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	output, err := tool.probe(probeCtx).CombinedOutput()
	if err != nil {
		if probeCtx.Err() != nil {
			err = errors.Join(err, probeCtx.Err())
		}
		return Result{Tool: tool, Path: path, Version: firstLine(string(output)), Err: err}
	}

	return Result{Tool: tool, Path: path, Version: firstLine(string(output))}
}

func firstLine(value string) string {
	value = strings.TrimSpace(value)
	if value == "" {
		return ""
	}
	if index := strings.IndexByte(value, '\n'); index >= 0 {
		value = value[:index]
	}
	return strings.TrimSpace(value)
}
