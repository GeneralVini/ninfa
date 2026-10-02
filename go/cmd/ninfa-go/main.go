// Command ninfa-go is the experimental bootstrap for Ninfa's future Go control plane.
// It is intentionally not the public Ninfa CLI yet; bin/ninfa remains authoritative.
package main

import (
	"context"
	"fmt"
	"io"
	"os"
	"os/signal"
	"runtime"
	"syscall"

	"github.com/GeneralVini/ninfa/go/internal/doctor"
	"github.com/GeneralVini/ninfa/go/internal/version"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	os.Exit(run(ctx, os.Args[1:], os.Stdout, os.Stderr))
}

func run(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	if len(args) == 0 || (len(args) == 1 && (args[0] == "--help" || args[0] == "help")) {
		printUsage(stdout)
		return 0
	}
	if len(args) != 1 {
		printUsage(stderr)
		return 2
	}

	switch args[0] {
	case "version":
		fmt.Fprintf(stdout, "ninfa-go %s; %s\n", version.String(), runtime.Version())
		return 0
	case "doctor":
		return runDoctor(ctx, stdout, stderr)
	default:
		fmt.Fprintf(stderr, "comando desconhecido: %s\n", args[0])
		printUsage(stderr)
		return 2
	}
}

func runDoctor(ctx context.Context, stdout, stderr io.Writer) int {
	checker := doctor.Checker{}
	failed := false

	for _, tool := range doctor.DefaultTools() {
		result := checker.Check(ctx, tool)
		if result.Err == nil {
			fmt.Fprintf(stdout, "[OK] %-12s %s\n", tool.Name, result.Version)
			continue
		}

		if tool.Required {
			failed = true
			fmt.Fprintf(stderr, "[ERRO] %-10s indisponível: %v\n", tool.Name, result.Err)
			continue
		}

		fmt.Fprintf(stdout, "[OPCIONAL] %-7s indisponível\n", tool.Name)
	}

	if failed {
		return 1
	}
	return 0
}

func printUsage(writer io.Writer) {
	fmt.Fprintln(writer, "Uso: ninfa-go <version|doctor>")
	fmt.Fprintln(writer, "")
	fmt.Fprintln(writer, "Bootstrap experimental; o CLI público continua sendo bin/ninfa.")
}
