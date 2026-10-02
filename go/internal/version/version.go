// Package version exposes build metadata for the experimental Go control plane.
package version

import "fmt"

var (
	// Version is the semantic/release version injected at build time.
	Version = "dev"
	// Commit is the source revision injected at build time.
	Commit = "unknown"
)

// String returns stable human-readable build metadata without timestamps.
func String() string {
	return fmt.Sprintf("%s (commit %s)", Version, Commit)
}
