# A Dockerized LAMP app: upload files, share links<br/>(and architecture considerations at a larger scale)

This is a simple LAMP stack web application that enables one to upload files, store the uploaded files <b>encrypted-at-rest</b>, and securely share links for downloading. To keep it secure, these links expire in a day, and the uploader can protect the link with a password.

The primary point of this application is <b>not</b> to show how to write a file-sharing application with Apache and PHP - hence the code is simple, it does not have bells and whistles. This is just an example functionality - based on which, my goal is to have an architectural discussion on how such a cloud-native application could be run at scale using docker containers - securly, catering to millions of users. This particular application, for the sake of simplicity, does not use a database, but I hope to shed some light on all such aspects using this code as the starting point.

## How to run and test this app

First things first. These instructions will get you a copy of the project up and running on your local machine or any Linux VM in AWS or Azure or anywhere else - as long as it is running Docker.

### Set up your Docker host

To run this code, you need a Linux host running Docker. It does not matter how you get one or where you are running it. I will not go into the details of how to get one up and running, but there are instruction sets readily available for [AWS](http://docs.aws.amazon.com/AmazonECS/latest/developerguide/docker-basics.html) or [Azure](https://docs.microsoft.com/en-us/azure/virtual-machines/virtual-machines-linux-dockerextension).

### Get the code and run it

Once you are logged into a Linux host running Docker, clone this repository, and cd into *file-upload-link-share-simple* directory
```
XXXXX@MyDockerVM:~/file-upload-link-share-simple$ ls -l
total 12
drwxrwxr-x 2 Azure123 Azure123 4096 Dec  9 01:45 code
-rw-rw-r-- 1 Azure123 Azure123  249 Dec  9 01:45 Dockerfile
-rw-rw-r-- 1 Azure123 Azure123  133 Dec  9 01:45 README.md
```
Check if docker daemon is running, and if it is, build the docker image for this application (you can tag your image anything you want, I have tagged it "koushik/fileuploadsimple" below)
```
docker build -t koushik/fileuploadsimple .
```
Once the image is built, check the local image repository
```
docker images
```
Run the container, forwarding port 80 to some port of your host that you can access from the browser. In the example below, I have forwarded 80 to 80, as my VM on the cloud had inbound HTTP traffic allowed on port 80
```
docker run -d -p 80:80 koushik/fileuploadsimple
```
Access the web application from your browser
```
http://[[your server's DNS name or IP Address:Port if non-80]]/form.html
```

### Functionality, assumptions, expected behavior

**How the password-protection feature works**: If the uploader specifies a password to protect the file, the downloader will have to specify the password directly in the URL/link as a query string parameter, *"password"*. *The system-generated link adds this query parameter at the end without the value*. Therefore, the link-holder has to do is copy the link, paste it in the browser's address bar, and type the password at the end. If the password is correct, it will work. This also means that *if a password is provided, the generated link will not work as-is*, and there is ample warning and usage instruction provided on the page that displays the link.

(The rationale behind this design choice is elaborated [below](https://github.com/kbxkb/file-upload-link-share-simple/blob/master/README.md#authorization---why-password-on-the-url))

**How the link-expiration feature works**: To keep it simple, I compare the unix timestamp of the download request with that of the upload. If the difference is > 86,400 seconds, request is declined. There is no purging built in as of now - the file stays.

**The form** itself is self-explanatory. Things to keep in mind:
* I used an arbitrary upper file size limit of 1 MB. However, this can be easily increased - uploading a larger file will obviously take longer, but some UI animation magic may be done to keep the user interested. The encryption-at-rest feature is implemented using openssl - and the file is encrypted while writing, decrypted while reading. The larger the file, the longer will that take as well. I have some ideas to scale this design in the later sections (under *[Architectural Considerations/ Security](https://github.com/kbxkb/file-upload-link-share-simple/blob/master/README.md#security-at-scale)*).
* I set rules for the password so that I do not have to deal with special characters that the user can input. This enabled me to write something quickly, as the password is meant to be used directly on the download URL, and I did not want to deal with time-consuming URL santization code - which are all easy, but spending time with that was not the point of this app.

**Multiple uploads and management**: To keep things simple, if you upload the same file multiple times, the older ones are overwritten each time. There is no version control. There is no content management - once you upload a few files, you cannot come back and regenerate a link without uploading again. If you shared a link with someone, and they have let it expire, you have to upload it again to get a new link. These are all potential enhancements, and fairly easy to achieve, it is just more code. At some point, it is easy to get to a stage where you will need a database to manage the metadata - and then there will be more *state* to the system, and that state and persistence can let us do better things than what this barebone app cannot do now.

**TO DO** - This entire discussion is written with a fireside architectural chat in mind. Read on, and all the TO DO-s I have in mind will be revealed!

## Architectural Considerations for Scalability

### A better design for Storage (and one of the reasons to choose Containerization)

The current code does nothing special to store the uploaded files. It just uses the disk. As written, it does not even use the host's disk, it uses the filesystem inside the container, which is volatile and hence not recommended at all. I will explain why I made that choice in a little bit. But before that, let us think through the topic of portability at scale.

What will make this web application truly portable? The PHP and Apache parts are very portable, all platforms/ Linux distros/ public or private clouds will run them. It then boils down to the storage of the uploaded files. If we were to scale this application to millions of users, we will need a large and safe (read *backed-up, disaster-proof*) space to store all the uploaded files. That brings us to the cloud - one of the cheaper ways to store tons of stuff. But then as soon as you choose one of the public clouds for storage, portability is at risk because the application will now have to use the storage APIs/SDK specific to that platform.

It is clear, therefore, that we have to build an abstraction layer for persistent storage. Docker containers provide a great way to do that using [named volumes](https://docs.docker.com/engine/tutorials/dockervolumes/) or data-only containers. If your application is made up of 4 or 5 containers, imagine one of them being your *[storage micro-service](http://www.tricksofthetrades.net/2016/03/14/docker-data-volumes/)*. It probably uses a [volume plugin](https://docs.docker.com/engine/extend/plugins_volume/) to abstract the storage out to AWS EBS or Azure Files - or a Ceph or GlusterFS cluster (or [Swift](http://docs.openstack.org/developer/swift/) if we are using OpenStack to cloudify a private datacenter). The [list](https://docs.docker.com/engine/extend/legacy_plugins/) of such plugins is already quite long, and growing longer every month. The other containers then can use the --volumes-from option to mount these persistent data volumes from the storage micro-service container. We have to be careful in choosing the right storage layer, as some of these plugins (like [flocker](https://clusterhq.com/flocker/introduction/) are still maturing and may have bugs, espceially around the drivers being written for various shared storage clusters like Ceph.

In this current codebase, I have kept it quick and simple by storing the files inside the container's filesystem itself. The slightly more secure solution would be to map the host machine's disk into the container using the -v flag, and storing the files on some location of the docker host. However, seeing that my host was a VM on the cloud, I saw little point in that. Coupled with the fact that apache2 cannot write to a mounted volume owned by root, I would have needed to implement the solution described [here](http://stackoverflow.com/questions/23544282/what-is-the-best-way-to-manage-permissions-for-docker-shared-volumes). I have left that as an exercise for the near future.

Another aspect of managing storage is to be able to clean up/ purge after a certain time - in essence expiring the file itself. If such a requirement exists, we can either use a metadata store - or we can track upload/ access times as file attributes - as many filesystems now support custom [extended attributes](http://man7.org/linux/man-pages/man5/attr.5.html).

### Security at Scale

Currently, I use inline PHP [openssl-encrypt](http://php.net/openssl-encrypt) to encrypt the file at rest while it is being uploaded, and [openssl-decrypt](http://php.net/openssl-decrypt) to decrypt it while it is being downloaded. While these are handy PHP functions, and will work fairly well unless the file sizes are not very large, this is **not** a very scalable solution. Encrypting and decrypting large files inline will limit the concurrent maximum number of users the system can support.

There are several high-level architectural solutions for this problem. One way is to let users choose client-side encryption if they so desire. Javascript frameworks like [crypto-js](https://code.google.com/archive/p/crypto-js/) or [crypto-browserify](https://github.com/crypto-browserify/crypto-browserify) can be used for this, like [this POC](https://github.com/hellais/up-crypt) does. The advantage of this solution is that encryption/ decryption does not bog the server down by using up all the compute resources - hence it can scale to a higher concurrency. Admittedly, you have to create a specially designed download page instead of sharing a simple http/https link that can be used on any REST client. But hey - you got to do the work *somewhere*.

Another way to solve this problem is to decouple the encryption process on the server-side and make it *asynchronous*. Here, the file will get uploaded, but downloading it won't be allowed instantaneously. An asynchronous process will encrypt it in-place, and mark it as *downloadable*. Storing such metadata around the file will, of course, need a database - but a metadata management system will anyway become mandatory as we get to these complex requirements.

This is also a good example of where a **decoupled queue-based architecture will help us scale**. At production scale, this application should be split into layers where such resource-intensive tasks are happening at a different layer - and the upload servers are simply queueing the files for the encryption servers to pick them up from a secure location and encrypt them. I have implemented such a system for transcoding video files at Yahoo and Ooyala, and transcoding is a similar, if not more, CPU-bound task. More on this architecture later on, in the "Performance and Scalability" section below.

This last solution, or at least variations of it, is actually implemented under the hood by EBS or S3 or Azure - it is just we do not see it, and we just enjoy the fruits of someone else's labor. That brings us to yet another kind of solution to this problem - buy instead of build :) Just use docker volume plugins that lets you store the files in the public cloud and check the *encrypted* box!

How about encryption on the wire if we use server-side encryption? The obvious answer to this is to offer **https** download links, which is easy to implement, so that would be a TODO for this small project of mine.

### Authorization - why password on the URL?

While discussing security, I also wanted to touch on the authorization aspect of this application - the trick with the password is quick and dirty, but it is not a truly secure solution. I actually save the files on the server with the password as part of its name - I avoided using a database that way. In the minimum, we should change that to use a low-collision hash like SHA1 (or one of the SHA families) instead of using the raw password.

I also do not like the idea of passing the password as part of the URL - it makes the system only usable by geeks. HTTP Basic Authentication is a great solution for this. However, as securing an uploaded file using password is *optional* in this app, the HTTP Server, on receiving a GET request for a file download, has to actually take a peek at the resource itself (or query a metadata store) to check if it will respond with a 401 (which will prompt the browser to ask for a username and password), or just serve the file. Implementing this is possible, but needs more coding - and the point of the application was to start an architectural discussion instead of showcasing specific implementation.

Also, I was trying not to develop a special page/ form for initiating download - but keep the download URL simple and HTTP/REST-based, so that anyone can use it from any browser. Hence, I opted for passing the password as a query string parameter - and I said to myself, "I am anyway building this for geeks for now, so what the heck".

### High Availability, Fault Tolerance

A containerized solution will need a container orchestration platform with good scheduling prowess to ensure HA and Fault Tolerance. Luckily, we are not short on the choices here, and the current war going on to capture the market makes all of us winners. Kubernetes and DC/OS, in my opinion, are the fore-runners. [ECS](https://aws.amazon.com/ecs/) has proprietary scheduling, which encourages vendor lock-in more than [ACS](https://azure.microsoft.com/en-us/services/container-service/) does. But all public clouds let you run your own IaaS clusters running the scheduler of your choice - if you want more control.

A best practice for HA is to keep the application code as stateless as possible. The code as it is now is entirely stateless (not the file storage though, but we are just talking about the application code here). However, as metadata and session management requirements creep in, it may be a challenge to keep it that way - especially if we increase the maximum allowed file size and allow users to resume interrupted downloads.

Here, the context is great to discuss some alternatives to just using Apache httpd as the HTTP server. Apache Traffic Server is not as out-of-the-box as apache2, but it lets us create [plugins](https://docs.trafficserver.apache.org/en/4.2.x/sdk/how-to-create-trafficserver-plugins.en.html) that can hook into the PUT and GET requests. It is possible to write or modify some plugins to enable "streaming" of large files where the HTTP Server acts as a pass-through for the file's contents as it makes it way to its final storage destination. Such a design will let the solution scale to a much higher number of concurrent users as the memory on the server will be more efficiently used - it will need slightly powerful CPU-s to offset that. It will also be easier to implement "resume interrupted upload/ download" feature in that case as spooling of the file at the web server layer is not happening, so the transaction becomes more atomic, hence more fault-tolerant. Apache stores all uploaded files in a temp folder before moving them to the final destination. The size and the location of this temp location can be a point of failure at higher scale.

### Caching

Caching becomes relevant if some of the uploaded files gets very popular, and we see many downloads happening on these hot files. If this is just a personal file-sharing service, where each uploaded file is only downloaded a few times, caching may remain a lower priority. However, if it turns into the next youtube or dropbox, we will need something really scalable to cache files for high performace. There are several time-tested solutions, for example, [Nginx can be used to front Apache](https://blog.rackspace.com/nginx-support-enables-massive-web-application-scaling) for a PHP application like this.

Memcached is a widely used solution for caching, but it is not a great solution under two cases (a) for large files (b) if the traffic spikes too quickly, it may not dynamically scale well. Memcached can be used to cache negative responses to download requests like incorrect passwords or non-existing files. Redis is a great option, as it also has disk persistence built in, so the size of the cache is usually not a limiting factor. The PHP code itself can use [APC](http://pecl.php.net/package/APC) for getting maximum mileage out of a single server without being a distributed cache.

When it comes to the topic of caching for a web application, most discussion assume small objects. However, truly scalable caching for large objects like large files need custom solutions - and one that I built for Yahoo's CDN is one such customized solution.

If the solution becomes wildly popular, and people from all over the world start using it, using a commercial CDN like Akamai for caching may be a good idea.

### Performnce and Scalability - Design Considerations

I have already discussed some techniques like caching or choice of technology stacks (like ATS) for higher performance and scalability. Here are some more ideas.

**Compression** - Using client side compression libraries is a good idea to reduce the payload size while uploading. There is no reason why compression and encryption cannot be done together, and the file may be uploaded using both the techniques applied together at the client. This will result in quicker download times, reduce bandwidth consumption of the website (hence egress charges in a metered cloud world). This will also reduce the number of HTTP calls made against the server, allowing it to scale and perform better

**Decoupling resource-intensive tasks** like encryption, transcoding, etc. What if this is a video file uploading service, and instead of merely downloading, the users would want to *play* the files? Dealing with video adds considerations of a differet dimension. I will not go into painstaking details, but here is a quick summary: As video files can be consumed from various kinds of devices/ phones/ tablets, and the formatting and packaging of the video file that these clients can play differ from one to the other, serving video at scale literally means that you have to *transcode* and *dynamically package* the files into various formats. Then there is the question of adaptive streaming to adjust to the internet bandwidth of various users for a smooth end user playback experience - for which you have to deal with multiple bitrates. Transcoding, therefore, is a hugely resource intensive task - even greater than encryption or compression.

At a large scale, such tasks should be docoupled to increase overall fault-tolerance and scalability - so that the upload server nodes can scale independently of the encryption or transcoding server nodes. Such a globally distributed massive system has not yet been implemented using containers I suspect, but as long as we keep the computation stateless as much as possible, and the storage abstracted, conatiners can actualy help scale well. Imagine a resource orchestrator spinning up new containers based on encryption or transcoding load, and these containers, like bubbles, going away when its work is done, thereby instantly freeing resources for use by others. Kubernetes, in my opinion, still needs to mature to be able to handle such an orchestration need - simply because it is so new. Mesosphere DC/OS, on the other hand, have the schedulers (espceially Marathon, which synchornizes long running jobs) with the necessary muscles to handle such an orchestration as we speak. The container orchestration landscape is changing very fast, and I know that whatever I write may get obsolete quickly if I focus too much on the relative merits ad demerits of specific technologies ar war with each other!

**Benchmarking performace** - How many concurrent connections/ uploads can my server handle? How many concurrent downloads? We should use a modern load testing toolkit to measure and improve. If we do not measure, we cannot improve!

**Telemetry and Monitoring** - On the aspect of measuring, a good development practice is to put in telemetry and performance metrics liberally to let us gauge server performance at runtime. The public clouds usually do a good job in providing such statistics at the infrastrucure level, but we should add application level metrics (like encryption latency) as well. We should integrate with nagios or datadog or similar lightweight agent-based services to serve this purpose

### Load Balancing

Load Balancing solutions (as the application scales horizontally) are an important consideration. Public Cloud platforms have load balancers like ELB or Azure Load Balancers. However, sometimes they lack features like customizable routing rules. For example, a feature like resuming interrupted download may need sticky sessions based on client IP - which is a fairly common need for web applications handling sessions. It is a common thing to trust a stand-alone load balancer like HAProxy more. The challenge becomes in scaling these standalone load-balancers if there is a sudden traffic spike. The cloud-native load balancers are particularly good at at that. Hence, there are pros and cons - but for this application, the cloud-native solutions should be good enough.

### Database

Once we go past the simple tasks this code performs and start managing the content, storing the state of each file, passwords (hashed and salted), etc. - we will eventually need a database. This partiuclar use-case calls for structured data, hence RDBMS will be a good choice. For such a simple use-case, any good RDBMS (like MySQL, postgreSQL, etc.) will work well. I do not see the total size of the metadata being very high, so in this case, advanced database scaling techniques like sharding will not be needed. Unless we are doing analytics on the data, we will not need NoSQL or Big Data constructs.

However, it is important to have a decoupled architecture for the database as well - i.e., database nodes should scale indpendent of the rest of the system.

### Global Replication

A word about global replication here. If a link-sharing system goes viral, then we should spread it out across the globe in different datacenters. In that case, top level routing and name resolution techniques will make sure that any user request lands on the nearest datacenter. The public clouds are very good with this thing, as they manage massive datacenters across the world. However, if someone in US uploads a file and shares it with someone else in Manilla - the download experience will be slow unless the file can be replicated to the other datacenters quickly.

The solution to this is global n-way replication. I have implmented such a solution at Yahoo using pub-sub mechanism. HTTP GET requests are redirected to the location with the file if replication, which is asynchronous, hasn't happened yet. In essence, the files can be downloaded from anywhere, and the entire network of globally distributed datacenters become a single REST-encapsulated massive file store.

Azure and AWS storage layers have redundancy built into them - they are very worried about losing your data. Azure offers a "read-only geo redundant storage" feature - where you can point your applications to a read-only copy of the data in a different region. In essence, they do the global replication for you under the hoods - and this underscores the value of public clouds - enterprise-grade features they have already implemented at scale are so attractive to just pay for and use, that it just does not make a whole lot of sense to build one for yourself from scratch any more!

## Feedback welcome

Feedback, comments are welcome and will be greatly appreciated! Please send me a pull request on any of the files with your feedback, and I will be happy to get in touch with you!
