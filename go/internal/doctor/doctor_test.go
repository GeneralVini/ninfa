package doctor

import (
	"context"
	"testing"
	"time"
)

func TestCheckGoVersion(t *testing.T) {
	var goTool *Tool
	tools := DefaultTools()
	for index := range tools {
		if tools[index].Name == "Go" {
			goTool = &tools[index]
			break
		}
	}
	if goTool == nil {
		t.Fatal("DefaultTools() does not contain Go")
	}

	result := (Checker{Timeout: 5 * time.Second}).Check(context.Background(), *goTool)
	if result.Err != nil {
		t.Skipf("Go executable is unavailable in the test environment: %v", result.Err)
	}
	if result.Path == "" {
		t.Fatal("Check() returned an empty executable path")
	}
	if result.Version == "" {
		t.Fatal("Check() returned an empty version")
	}
}

func TestCheckMissingTool(t *testing.T) {
	t.Setenv("PATH", t.TempDir())
	tool := DefaultTools()[0]

	result := (Checker{Timeout: time.Second}).Check(context.Background(), tool)
	if result.Err == nil {
		t.Fatal("Check() error = nil, want executable lookup failure")
	}
}

func TestFirstLine(t *testing.T) {
	if got, want := firstLine(" first\nsecond\n"), "first"; got != want {
		t.Fatalf("firstLine() = %q, want %q", got, want)
	}
}
