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
type Tool struct {
	Name     string
	Command  string
	Args     []string
	Required bool
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
		{Name: "PHP", Command: "php", Args: []string{"--version"}, Required: true},
		{Name: "Git", Command: "git", Args: []string{"--version"}, Required: true},
		{Name: "Go", Command: "go", Args: []string{"version"}, Required: true},
		{Name: "Make", Command: "make", Args: []string{"--version"}, Required: true},
		{Name: "Composer", Command: "composer", Args: []string{"--version"}, Required: false},
		{Name: "Semgrep", Command: "semgrep", Args: []string{"--version"}, Required: false},
		{Name: "Staticcheck", Command: "staticcheck", Args: []string{"-version"}, Required: false},
		{Name: "gosec", Command: "gosec", Args: []string{"-version"}, Required: false},
		{Name: "govulncheck", Command: "govulncheck", Args: []string{"-version"}, Required: false},
	}
}

// Check locates a tool and executes only its fixed version arguments.
// No shell is involved and the probe is cancelled when the context or timeout expires.
func (c Checker) Check(ctx context.Context, tool Tool) Result {
	path, err := exec.LookPath(tool.Command)
	if err != nil {
		return Result{Tool: tool, Err: err}
	}

	timeout := c.Timeout
	if timeout <= 0 {
		timeout = 3 * time.Second
	}

	probeCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	output, err := exec.CommandContext(probeCtx, path, tool.Args...).CombinedOutput()
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
