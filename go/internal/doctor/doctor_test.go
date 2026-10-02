package doctor

import (
	"context"
	"os/exec"
	"testing"
	"time"
)

func TestCheckGoVersion(t *testing.T) {
	if _, err := exec.LookPath("go"); err != nil {
		t.Skip("Go executable is unavailable in the test environment")
	}

	result := (Checker{Timeout: 5 * time.Second}).Check(context.Background(), Tool{
		Name:     "Go",
		Command:  "go",
		Args:     []string{"version"},
		Required: true,
	})

	if result.Err != nil {
		t.Fatalf("Check() error = %v", result.Err)
	}
	if result.Path == "" {
		t.Fatal("Check() returned an empty executable path")
	}
	if result.Version == "" {
		t.Fatal("Check() returned an empty version")
	}
}

func TestCheckMissingTool(t *testing.T) {
	result := (Checker{Timeout: time.Second}).Check(context.Background(), Tool{
		Name:     "Missing",
		Command:  "ninfa-tool-that-must-not-exist-8eb05ac7",
		Required: true,
	})

	if result.Err == nil {
		t.Fatal("Check() error = nil, want executable lookup failure")
	}
}

func TestFirstLine(t *testing.T) {
	if got, want := firstLine(" first\nsecond\n"), "first"; got != want {
		t.Fatalf("firstLine() = %q, want %q", got, want)
	}
}
