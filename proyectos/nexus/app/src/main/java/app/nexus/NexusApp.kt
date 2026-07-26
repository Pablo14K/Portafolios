package app.nexus

import android.app.Application
import app.nexus.di.Graph

class NexusApp : Application() {

    lateinit var graph: Graph
        private set

    override fun onCreate() {
        super.onCreate()
        graph = Graph.get(this)
    }
}
