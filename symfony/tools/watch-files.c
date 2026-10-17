/* Thin Linux kernel event bridge; no game logic or control. PHP owns its input pipe. */
#include <sys/inotify.h>
#include <sys/stat.h>
#include <poll.h>
#include <unistd.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <limits.h>

struct target { const char *token, *path; char parent[PATH_MAX], name[PATH_MAX]; int wd; };
static int bind_target(int fd, struct target *target) {
    struct stat info;
    if (strlen(target->path) >= PATH_MAX) return -1;
    strcpy(target->parent, target->path);
    char *slash = strrchr(target->parent, '/'); if (!slash) return -1; *slash = 0;
    while (stat(target->parent, &info) || !S_ISDIR(info.st_mode)) {
        slash = strrchr(target->parent, '/'); if (!slash) return -1;
        if (slash == target->parent) { slash[1] = 0; break; } *slash = 0;
    }
    size_t offset = strlen(target->parent); if (target->path[offset] == '/') ++offset;
    strcpy(target->name, target->path+offset); slash = strchr(target->name, '/'); if (slash) *slash = 0;
    target->wd = inotify_add_watch(fd, target->parent, IN_CLOSE_WRITE|IN_MODIFY|IN_CREATE|IN_MOVED_TO|IN_DELETE|IN_DELETE_SELF|IN_MOVE_SELF);
    return target->wd < 0 ? -1 : 0;
}
static void emit(const char *token) {
    const char *colon = strchr(token, ':'); if (!colon) return;
    printf("{\"kind\":\"%.*s\",\"server_id\":\"%s\"}\n", (int)(colon-token), token, colon+1);
}
int main(int argc, char **argv) {
    if ((argc-1)%2 || argc>257) return 2;
    int count = (argc-1)/2, fd = inotify_init1(IN_CLOEXEC); if (fd<0) return 3;
    struct target *targets = calloc((size_t)count, sizeof(*targets)); if (count && !targets) return 4;
    int degraded = 0;
    for (int i=0; i<count; ++i) {
        targets[i].token = argv[i*2+1]; targets[i].path = argv[i*2+2];
        if (strspn(targets[i].token, "abcdefghijklmnopqrstuvwxyz0123456789_:-") != strlen(targets[i].token)) return 2;
        if (bind_target(fd, &targets[i])) degraded = 1;
    }
    setvbuf(stdout, NULL, _IOLBF, 0);
    printf("{\"kind\":\"ready\",\"degraded\":%s}\n", degraded ? "true" : "false");
    struct pollfd fds[2] = {{fd, POLLIN, 0}, {STDIN_FILENO, POLLIN|POLLHUP, 0}};
    union { char bytes[65536]; struct inotify_event alignment; } buffer;
    while (poll(fds, 2, -1)>=0) {
        if (fds[1].revents & (POLLIN|POLLHUP|POLLERR)) { char byte; if (read(STDIN_FILENO, &byte, 1)<=0) break; }
        if (!(fds[0].revents & POLLIN)) continue;
        ssize_t size = read(fd, buffer.bytes, sizeof(buffer.bytes)); if (size<=0) break;
        for (char *at=buffer.bytes; at<buffer.bytes+size;) {
            struct inotify_event *event = (struct inotify_event *)at;
            if (event->mask & IN_Q_OVERFLOW) puts("{\"kind\":\"rescan\"}");
            for (int i=0; i<count; ++i) {
                if (targets[i].wd != event->wd) continue;
                if (!event->len || !strcmp(event->name, targets[i].name)) { emit(targets[i].token); bind_target(fd, &targets[i]); }
            }
            at += sizeof(*event)+event->len;
        }
    }
    free(targets); close(fd); return 0;
}
