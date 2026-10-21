#!/usr/bin/env bash
# shellcheck disable=SC2016
# acg-faka 容器一键升级：
#
#   curl -fsSL https://raw.githubusercontent.com/lizhipay/acg-faka/main/docker/upgrade.sh | bash
#
# 自动找到本机的 acg-faka 容器，拉最新镜像，用原来的端口、数据卷、环境变量、重启策略
# 重建。站点数据都在 /data 卷里，只换程序不碰数据；新容器起不来会自动换回旧容器。
# compose 安装（本机构建）的改走「更新源码 + 重新构建」。
#
# 本机有多个 acg-faka 容器时指定名字：      ... | bash -s -- 容器名
# 换镜像地址（例如自建的镜像加速）：        ... | ACG_IMAGE=地址:latest bash
#
# 刻意只用 bash 3.2 的语法：macOS 自带的 bash 也能跑。
set -euo pipefail

DEFAULT_IMAGE=ghcr.io/lizhipay/acg-faka:latest
ENTRYPOINT_MARK=acg-faka-entrypoint
READY_TIMEOUT=180

say() { printf '\033[32m[acg-faka]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[acg-faka]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[31m[acg-faka]\033[0m %s\n' "$*" >&2; exit 1; }

inspect() { docker inspect -f "$2" "$1"; }

label() {
    local v
    v="$(inspect "$1" "{{index .Config.Labels \"$2\"}}")"
    [ "${v}" = "<no value>" ] || printf '%s' "${v}"
}

is_ours() {
    local ep image
    ep="$(inspect "$1" '{{join .Config.Entrypoint " "}}' 2>/dev/null)" || return 1
    image="$(inspect "$1" '{{.Config.Image}}' 2>/dev/null)" || return 1
    case "${ep}" in *"${ENTRYPOINT_MARK}"*) return 0 ;; esac
    case "${image}" in *acg-faka*) return 0 ;; esac
    return 1
}

find_target() {
    if [ -n "${1:-}" ]; then
        is_ours "$1" || die "容器 $1 不存在，或者不是 acg-faka 的容器"
        printf '%s\n' "$1"
        return
    fi
    local found="" n=0 id name
    for id in $(docker ps -aq); do
        if is_ours "${id}"; then
            name="$(inspect "${id}" '{{.Name}}')"
            found="${found}${name#/}"$'\n'
            n=$((n + 1))
        fi
    done
    if [ "${n}" -eq 0 ]; then
        die "本机没有找到 acg-faka 容器"
    fi
    if [ "${n}" -gt 1 ]; then
        warn "找到多个 acg-faka 容器："
        printf '%s' "${found}" | sed 's/^/    /' >&2
        die "请指定要升级哪一个：curl -fsSL <脚本地址> | bash -s -- 容器名"
    fi
    printf '%s' "${found}" | head -n1
}

# 镜像地址的标签换成 latest：ghcr.io/x/acg-faka:3.8.4 -> ghcr.io/x/acg-faka:latest
latest_of() {
    local ref="${1%%@*}" base
    case "${ref}" in sha256:*|"") printf '%s\n' "${DEFAULT_IMAGE}"; return ;; esac
    base="${ref##*/}"
    case "${base}" in *:*) ref="${ref%:*}" ;; esac
    printf '%s:latest\n' "${ref}"
}

wait_ready() {
    local name="$1" i=0
    while [ "${i}" -lt "${READY_TIMEOUT}" ]; do
        # 带重启策略的容器崩了会被反复拉起，Running 时真时假，看重启次数才可靠
        if [ "$(inspect "${name}" '{{.State.Running}} {{.RestartCount}}' 2>/dev/null)" != "true 0" ]; then
            return 1
        fi
        if docker exec "${name}" php -r 'exit(trim((string)@file_get_contents("http://127.0.0.1/healthz")) === "pong" ? 0 : 1);' >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        i=$((i + 2))
    done
    return 1
}

report() {
    local info
    info="$(docker exec "$1" php -r 'echo PHP_VERSION, "，session 硬化", ini_get("session.harden") === "1" ? "已开启" : "未开启";' 2>/dev/null || true)"
    say "当前：PHP ${info:-未知}"
}

lines_of() { docker inspect -f "$2" "$1" | sed '/^$/d'; }

# 容器里有、但镜像本身没有的条目，才是用户自己加的（环境变量、标签）
user_added() {
    local container="$1" image="$2" tpl="$3" from_image line
    from_image="$(docker image inspect -f "${tpl}" "${image}")" || die "读不到旧镜像的配置，已停止"
    lines_of "${container}" "${tpl}" | while IFS= read -r line; do
        printf '%s\n' "${from_image}" | grep -Fxq -- "${line}" || printf '%s\n' "${line}"
    done
}

upgrade_plain() {
    local name="$1" old_ref old_id image new_id data args line t n s d rw
    old_ref="$(inspect "${name}" '{{.Config.Image}}')"
    old_id="$(inspect "${name}" '{{.Image}}')"
    image="${ACG_IMAGE:-$(latest_of "${old_ref}")}"

    data="$(inspect "${name}" '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Type}} {{if .Name}}{{.Name}}{{else}}{{.Source}}{{end}}{{end}}{{end}}')"
    [ -n "${data}" ] || die "容器 ${name} 的 /data 没有挂载数据卷，重建会丢数据，已停止。请先备份：docker cp ${name}:/data ./acg-data-backup"
    say "数据卷：${data}（升级只换程序，不动它）"

    say "拉取最新镜像 ${image} …"
    docker pull "${image}" || die "镜像拉取失败（多半是网络连不上 ghcr.io）。可以给 Docker 配代理或镜像加速后重试；有可用的镜像地址时：curl ... | ACG_IMAGE=地址:latest bash"
    case "$(docker image inspect -f '{{join .Config.Entrypoint " "}}' "${image}")" in
        *"${ENTRYPOINT_MARK}"*) ;;
        *) die "${image} 不是 acg-faka 的镜像，已停止" ;;
    esac

    new_id="$(docker image inspect -f '{{.Id}}' "${image}")"
    if [ "${new_id}" = "${old_id}" ]; then
        say "已经是最新版，不用升级"
        report "${name}"
        return
    fi

    args=(-d --name "${name}")

    local rp rc nm
    rp="$(inspect "${name}" '{{.HostConfig.RestartPolicy.Name}}')"
    rc="$(inspect "${name}" '{{.HostConfig.RestartPolicy.MaximumRetryCount}}')"
    case "${rp}" in
        ""|no) ;;
        on-failure) if [ "${rc}" -gt 0 ]; then args+=(--restart "on-failure:${rc}"); else args+=(--restart on-failure); fi ;;
        *) args+=(--restart "${rp}") ;;
    esac

    nm="$(inspect "${name}" '{{.HostConfig.NetworkMode}}')"
    case "${nm}" in default|bridge|"") ;; *) args+=(--network "${nm}") ;; esac

    if [ "${nm}" != "host" ]; then
        while IFS='|' read -r t n s; do
            [ -n "${s}" ] || continue
            case "${t}" in *:*) t="[${t}]" ;; esac
            if [ -n "${t}" ]; then args+=(-p "${t}:${n}:${s}"); elif [ -n "${n}" ]; then args+=(-p "${n}:${s}"); else args+=(-p "${s}"); fi
        done < <(lines_of "${name}" '{{range $p, $b := .HostConfig.PortBindings}}{{range $b}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}')
    fi

    while IFS='|' read -r t n s d rw; do
        [ -n "${d}" ] || continue
        case "${t}" in
            volume) line="${n}:${d}" ;;
            bind) line="${s}:${d}" ;;
            *) warn "跳过不认识的挂载类型 ${t}：${d}"; continue ;;
        esac
        [ "${rw}" = "true" ] || line="${line}:ro"
        args+=(-v "${line}")
    done < <(lines_of "${name}" '{{range .Mounts}}{{.Type}}|{{.Name}}|{{.Source}}|{{.Destination}}|{{.RW}}{{println}}{{end}}')

    while IFS= read -r line; do args+=(-e "${line}"); done < <(user_added "${name}" "${old_id}" '{{range .Config.Env}}{{println .}}{{end}}')
    while IFS= read -r line; do args+=(--label "${line}"); done < <(user_added "${name}" "${old_id}" '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}{{println}}{{end}}')
    while IFS= read -r line; do args+=(--add-host "${line}"); done < <(lines_of "${name}" '{{range .HostConfig.ExtraHosts}}{{println .}}{{end}}')

    local mem cpus logd hostname id
    mem="$(inspect "${name}" '{{.HostConfig.Memory}}')"
    [ "${mem}" = "0" ] || args+=(--memory "${mem}")
    cpus="$(inspect "${name}" '{{.HostConfig.NanoCpus}}')"
    [ "${cpus}" = "0" ] || args+=(--cpus "$(awk -v n="${cpus}" 'BEGIN { printf "%g", n / 1e9 }')")
    logd="$(inspect "${name}" '{{.HostConfig.LogConfig.Type}}')"
    [ -z "${logd}" ] || args+=(--log-driver "${logd}")
    while IFS= read -r line; do args+=(--log-opt "${line}"); done < <(lines_of "${name}" '{{range $k, $v := .HostConfig.LogConfig.Config}}{{$k}}={{$v}}{{println}}{{end}}')
    id="$(inspect "${name}" '{{.Id}}')"
    hostname="$(inspect "${name}" '{{.Config.Hostname}}')"
    [ "${hostname}" = "${id:0:12}" ] || args+=(--hostname "${hostname}")

    local bak
    bak="${name}-before-upgrade-$(date +%Y%m%d%H%M%S)"
    say "停止旧容器 ${name} …"
    docker update --restart no "${name}" >/dev/null
    docker stop -t 60 "${name}" >/dev/null
    docker rename "${name}" "${bak}"

    rollback() {
        warn "新版启动失败，最后几行日志："
        docker logs --tail 30 "${name}" 2>&1 | sed 's/^/    /' >&2 || true
        docker rm -f "${name}" >/dev/null 2>&1 || true
        docker rename "${bak}" "${name}"
        docker update --restart "${rp:-no}" "${name}" >/dev/null 2>&1 || true
        docker start "${name}" >/dev/null
        say "已换回旧容器，等待它恢复 …"
        wait_ready "${name}" || die "旧容器也没能正常启动，请执行 docker logs ${name} 查看原因。数据仍完整保存在数据卷里。"
        die "升级没成功，已自动换回旧版本，站点已恢复。请把上面的日志发给我们排查。"
    }

    say "用新镜像重建容器 …"
    docker run "${args[@]}" "${image}" >/dev/null || rollback
    say "等待站点就绪（首次启动可能要一两分钟）…"
    wait_ready "${name}" || rollback

    docker rm "${bak}" >/dev/null
    docker image rm "${old_id}" >/dev/null 2>&1 || true
    say "升级完成"
    report "${name}"
}

upgrade_compose() {
    local name="$1" project wd files service dc args f
    project="$(label "${name}" com.docker.compose.project)"
    wd="$(label "${name}" com.docker.compose.project.working_dir)"
    files="$(label "${name}" com.docker.compose.project.config_files)"
    service="$(label "${name}" com.docker.compose.service)"
    [ -d "${wd}" ] || die "找不到 compose 目录 ${wd}"

    if docker compose version >/dev/null 2>&1; then
        dc=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        dc=(docker-compose)
    else
        die "没有找到 docker compose"
    fi
    args=(-p "${project}" --project-directory "${wd}")
    local IFS_SAVE="${IFS}"
    IFS=','
    for f in ${files}; do args+=(-f "${f}"); done
    IFS="${IFS_SAVE}"

    say "compose 项目 ${project}（${wd}），服务 ${service}"
    # 看 compose 规范化后的配置里这个服务有没有 build:（镜像的 RepoDigests 在 containerd 存储下不可靠）
    if "${dc[@]}" "${args[@]}" config 2>/dev/null | awk -v svc="${service}" '
        /^services:/ { in_services = 1; next }
        in_services && /^[^ ]/ { in_services = 0 }
        in_services && /^  [^ ]/ { current = $1; sub(/:$/, "", current) }
        in_services && current == svc && /^    build:/ { found = 1 }
        END { exit !found }'; then
        if [ -d "${wd}/.git" ]; then
            say "更新源码 …"
            # sudo 执行时 git 会因目录属主不同拒绝操作（dubious ownership），这里只对这个目录放行
            git -c safe.directory="${wd}" -C "${wd}" pull --ff-only || die "源码更新失败（多半是本地改过文件）。请在 ${wd} 处理后重试"
        else
            warn "${wd} 不是 git 仓库，没法自动更新源码。若还没换成新版源码，请先下载覆盖，再重新执行本命令"
        fi
        say "重新构建镜像（要编译 PHP，几分钟到十几分钟）…"
        "${dc[@]}" "${args[@]}" build --pull "${service}"
    else
        say "拉取最新镜像 …"
        "${dc[@]}" "${args[@]}" pull "${service}"
    fi
    "${dc[@]}" "${args[@]}" up -d "${service}"

    say "等待站点就绪 …"
    wait_ready "${name}" || die "新容器没有就绪，请查看日志：${dc[*]} -p ${project} logs ${service}"
    say "升级完成"
    report "${name}"
}

main() {
    command -v docker >/dev/null 2>&1 || die "这台机器上没有 docker 命令"
    docker info >/dev/null 2>&1 || die "没有权限操作 Docker，请用 root 执行，或在命令前加 sudo"

    local name
    name="$(find_target "${1:-}")"
    say "要升级的容器：${name}"

    if [ -n "$(label "${name}" com.docker.compose.project)" ]; then
        upgrade_compose "${name}"
    else
        upgrade_plain "${name}"
    fi
}

main "$@"
