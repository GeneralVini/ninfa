package version

import "testing"

func TestString(t *testing.T) {
	oldVersion, oldCommit := Version, Commit
	t.Cleanup(func() {
		Version, Commit = oldVersion, oldCommit
	})

	Version = "0.2.0-test"
	Commit = "abc123"

	if got, want := String(), "0.2.0-test (commit abc123)"; got != want {
		t.Fatalf("String() = %q, want %q", got, want)
	}
}
