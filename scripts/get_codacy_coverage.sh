#!/usr/bin/env bash
# Codacy API Coverage Calls for SugarCraft
# Requires: CODACY_API_TOKEN environment variable

set -euo pipefail

# Configuration
PROVIDER="${CODACY_PROVIDER:-gh}"
ORGANIZATION="${CODACY_ORG:-detain}"
REPOSITORY="${CODACY_REPO:-sugarcraft}"
API_BASE="https://api.codacy.com/api/v3"
API_TOKEN="${CODACY_API_TOKEN:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

usage() {
    cat <<EOF
Usage: $0 [command]

Commands:
  status          List most recent coverage reports for the repository (listCoverageReports)
  commit-reports  List coverage reports for a specific commit
  report          Get a specific coverage report with per-file data
  pr-coverage     Get pull request coverage delta (deltaCoverage, diffCoverage)
  pr-files        Get per-file coverage for a pull request
  help            Show this help message

Environment Variables:
  CODACY_API_TOKEN    Your Codacy API token (required)
  CODACY_PROVIDER      Git provider (default: gh)
  CODACY_ORG          Organization name (default: detain)
  CODACY_REPO         Repository name (default: sugarcraft)

Examples:
  # Get repository coverage status
  CODACY_API_TOKEN=your_token $0 status

  # Get PR coverage for PR #42
  CODACY_API_TOKEN=your_token $0 pr-coverage 42

  # Get reports for main branch latest commit
  CODACY_API_TOKEN=your_token $0 commit-reports main

  # Get specific report with per-file data
  CODACY_API_TOKEN=your_token $0 report main <reportUuid>

Notes:
  - listCoverageReports returns commit data but NOT reportUuid
  - Use listCommitCoverageReports to get reportUuid values
  - getCoverageReport then returns per-file coverage using reportUuid
  - For public repos, getRepositoryPullRequestCoverage does NOT require auth
EOF
}

check_token() {
    if [[ -z "$API_TOKEN" ]]; then
        echo -e "${RED}Error: CODACY_API_TOKEN is not set${NC}" >&2
        exit 1
    fi
}

headers() {
    echo -H "Accept: application/json"
    echo -H "api-token: ${API_TOKEN}"
}

# ============================================
# listCoverageReports
# GET /organizations/{provider}/{remoteOrganizationName}/repositories/{repositoryName}/coverage/status
# ============================================
cmd_status() {
    check_token
    echo -e "${GREEN}Fetching repository coverage status...${NC}"
    echo "Endpoint: listCoverageReports"
    echo ""
    curl -s -X GET "${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/coverage/status" \
        $(headers) | jq .
}

# ============================================
# listCommitCoverageReports
# GET /organizations/{provider}/{remoteOrganizationName}/repositories/{repositoryName}/commits/{commitUuid}/coverage/reports
# ============================================
cmd_commit_reports() {
    check_token
    local commit="${1:-main}"
    echo -e "${GREEN}Fetching coverage reports for commit: ${commit}${NC}"
    echo "Endpoint: listCommitCoverageReports"
    echo ""
    curl -s -X GET "${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/commits/${commit}/coverage/reports" \
        $(headers) | jq .
}

# ============================================
# getCoverageReport
# GET /organizations/{provider}/{remoteOrganizationName}/repositories/{repositoryName}/commits/{commitUuid}/coverage/reports/{reportUuid}
# ============================================
cmd_report() {
    check_token
    local commit="${1:-main}"
    local report_uuid="${2:-}"
    
    if [[ -z "$report_uuid" ]]; then
        echo -e "${YELLOW}Usage: $0 report <commit> <reportUuid>${NC}" >&2
        echo "  Hint: Run '$0 commit-reports <commit>' first to get reportUuid values" >&2
        exit 1
    fi
    
    echo -e "${GREEN}Fetching coverage report ${report_uuid} for commit: ${commit}${NC}"
    echo "Endpoint: getCoverageReport"
    echo ""
    curl -s -X GET "${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/commits/${commit}/coverage/reports/${report_uuid}" \
        $(headers) | jq .
}

# ============================================
# getRepositoryPullRequestCoverage
# GET /coverage/organizations/{provider}/{remoteOrganizationName}/repositories/{repositoryName}/pull-requests/{pullRequestNumber}
# Note: Does NOT require auth for public repositories
# ============================================
cmd_pr_coverage() {
    local pr_number="${1:-}"
    
    if [[ -z "$pr_number" ]]; then
        echo -e "${YELLOW}Usage: $0 pr-coverage <pr_number>${NC}" >&2
        exit 1
    fi
    
    echo -e "${GREEN}Fetching coverage for PR #${pr_number}${NC}"
    echo "Endpoint: getRepositoryPullRequestCoverage"
    echo "Note: No auth required for public repositories"
    echo ""
    
    # Check if we have a token, use it if available
    local auth_header=""
    if [[ -n "$API_TOKEN" ]]; then
        auth_header="-H \"api-token: ${API_TOKEN}\""
    fi
    
    curl -s -X GET "${API_BASE}/coverage/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/pull-requests/${pr_number}" \
        -H "Accept: application/json" \
        ${auth_header} | jq .
}

# ============================================
# getRepositoryPullRequestFilesCoverage
# GET /coverage/organizations/{provider}/{remoteOrganizationName}/repositories/{repositoryName}/pull-requests/{pullRequestNumber}/files
# Note: Does NOT require auth for public repositories
# ============================================
cmd_pr_files() {
    local pr_number="${1:-}"
    
    if [[ -z "$pr_number" ]]; then
        echo -e "${YELLOW}Usage: $0 pr-files <pr_number>${NC}" >&2
        exit 1
    fi
    
    echo -e "${GREEN}Fetching per-file coverage for PR #${pr_number}${NC}"
    echo "Endpoint: getRepositoryPullRequestFilesCoverage"
    echo "Note: No auth required for public repositories"
    echo ""
    
    # Check if we have a token, use it if available
    local auth_header=""
    if [[ -n "$API_TOKEN" ]]; then
        auth_header="-H \"api-token: ${API_TOKEN}\""
    fi
    
    curl -s -X GET "${API_BASE}/coverage/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/pull-requests/${pr_number}/files" \
        -H "Accept: application/json" \
        ${auth_header} | jq .
}

# ============================================
# Quick flow: status -> commit-reports -> report
# ============================================
cmd_full_flow() {
    check_token
    
    echo -e "${GREEN}=== Full Coverage Flow ===${NC}"
    echo ""
    
    # Step 1: Get overall status
    echo -e "${YELLOW}Step 1: listCoverageReports (repository status)${NC}"
    echo "URL: ${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/coverage/status"
    local status_resp
    status_resp=$(curl -s -X GET "${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/coverage/status" \
        $(headers))
    echo "$status_resp" | jq '.' | head -30
    echo ""
    
    # Step 2: Get reports for a commit
    local commit="${1:-main}"
    echo -e "${YELLOW}Step 2: listCommitCoverageReports (reports for commit: ${commit})${NC}"
    echo "URL: ${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/commits/${commit}/coverage/reports"
    local reports_resp
    reports_resp=$(curl -s -X GET "${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/commits/${commit}/coverage/reports" \
        $(headers))
    echo "$reports_resp" | jq '.' | head -30
    echo ""
    
    # Extract first reportUuid if available
    local first_report_id
    first_report_id=$(echo "$reports_resp" | jq -r '.data.reports[0].reportId' 2>/dev/null || echo "")
    
    if [[ -n "$first_report_id" && "$first_report_id" != "null" ]]; then
        echo -e "${YELLOW}Step 3: getCoverageReport (using first reportId: ${first_report_id})${NC}"
        echo "URL: ${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/commits/${commit}/coverage/reports/${first_report_id}"
        curl -s -X GET "${API_BASE}/organizations/${PROVIDER}/${ORGANIZATION}/repositories/${REPOSITORY}/commits/${commit}/coverage/reports/${first_report_id}" \
            $(headers) | jq .
    else
        echo -e "${YELLOW}No reports found for commit ${commit}${NC}"
    fi
}

# Main
case "${1:-help}" in
    status)
        cmd_status
        ;;
    commit-reports)
        cmd_commit_reports "${2:-main}"
        ;;
    report)
        cmd_report "${2:-main}" "${3:-}"
        ;;
    pr-coverage)
        cmd_pr_coverage "${2:-}"
        ;;
    pr-files)
        cmd_pr_files "${2:-}"
        ;;
    full-flow)
        cmd_full_flow "${2:-main}"
        ;;
    help|--help|-h)
        usage
        ;;
    *)
        echo -e "${RED}Unknown command: $1${NC}" >&2
        usage
        exit 1
        ;;
esac
