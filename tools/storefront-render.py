"""Build and render the storefront's product imagery in Blender.

DESIGN.md's one-line description of the language is "photography-first": a
tile is a product resting on a surface and the type frames it. A hosting plan
has no photograph, so this renders one - an anodised slab, three-quarter,
studio-lit, on transparency - and the tiers are the same object in one, two
and three, which is a family a customer can read without a caption.

**Why a script rather than files somebody exported once.** The artwork is a
build output: the day the brand's accent changes, or somebody wants the slab
lighter, this is edited and re-run. A PNG in `public/` with no source is a PNG
nobody dares touch.

Run it with Blender's own Python, against a scene you do not mind losing:

    blender --background --python tools/storefront-render.py

It writes into `public/storefront/`, which is where `StorefrontImagery` looks.
"""

from __future__ import annotations

import math
import os
import sys

import bpy

# Where the shop looks. Relative to the repository root, which is this file's
# parent - not the current working directory, because Blender's is wherever it
# was launched from.
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'public', 'storefront')

# Aluminium, not graphite.
#
# The hero tile is black, and a graphite body on it is a silhouette: the first
# renders were handsome on their own and would have disappeared on the page
# they were made for. A light metal body against a dark face is the contrast
# that survives the tile - and it is what the reference's own product does.
BODY = (0.62, 0.63, 0.655, 1.0)

# The face. Near-black and smooth, so it takes the rim light as a highlight
# rather than as a wash - which is the whole difference between a render that
# reads as a device and one that reads as a floor tile. The first version had
# no face at all, and that is exactly what it looked like.
GLASS = (0.008, 0.009, 0.012, 1.0)


def clear_scene() -> None:
    """Empty the file, through the data API rather than through operators.

    `bpy.ops.object.select_all` and `bpy.ops.object.delete` need a context an
    operator would normally be run from, and when this script is driven from
    outside Blender's UI they do nothing at all - silently. Three renders were
    composed against the leftovers of the ones before them, which is why one
    unit kept coming back with a mirror-bright face nothing in the current
    scene could explain: it was a slab from two versions ago, still standing
    there with the old material on it.

    `bpy.data.objects.remove` does not care about context.
    """
    for obj in list(bpy.data.objects):
        bpy.data.objects.remove(obj, do_unlink=True)

    for block in (bpy.data.meshes, bpy.data.materials, bpy.data.lights, bpy.data.cameras):
        for item in list(block):
            if item.users == 0:
                block.remove(item)


def surface(name: str, colour, metallic: float, roughness: float) -> bpy.types.Material:
    material = bpy.data.materials.new(name)
    material.use_nodes = True

    bsdf = material.node_tree.nodes['Principled BSDF']
    bsdf.inputs['Base Color'].default_value = colour
    bsdf.inputs['Metallic'].default_value = metallic
    bsdf.inputs['Roughness'].default_value = roughness

    return material


def materials() -> tuple[bpy.types.Material, bpy.types.Material]:
    """Two: the body and the face. A product render has a body and a screen,
    and the contrast between them is what the eye reads as an object."""
    return (
        surface('Anodised', BODY, 0.95, 0.22),
        # A screen is dark with a sweep across it, not a window onto a
        # softbox. Roughness alone did not get there - see `black_card()`.
        surface('Glass', GLASS, 0.0, 0.22),
    )


def slab(name: str, location, rotation, skins) -> bpy.types.Object:
    """A device: a chamfered body with a glass face inset into its top.

    Thick enough that the chamfer catches a highlight along its length. The
    first version was a tenth of this and read as a tray.
    """
    body_material, glass_material = skins

    bpy.ops.mesh.primitive_cube_add(size=1.0, location=location, rotation=rotation)

    obj = bpy.context.active_object
    obj.name = name
    obj.scale = (1.30, 0.90, 0.17)

    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)

    bevel = obj.modifiers.new('Bevel', 'BEVEL')
    bevel.width = 0.032
    bevel.segments = 8
    bevel.limit_method = 'ANGLE'
    bevel.angle_limit = math.radians(30)

    bpy.ops.object.shade_smooth()

    # Smooth the bevel and keep the faces flat. `shade_auto_smooth` is the
    # 4.1+ spelling; older builds carry the mesh flag instead.
    if hasattr(bpy.ops.object, 'shade_auto_smooth'):
        bpy.ops.object.shade_auto_smooth(angle=math.radians(30))
    elif hasattr(obj.data, 'use_auto_smooth'):
        obj.data.use_auto_smooth = True
        obj.data.auto_smooth_angle = math.radians(30)

    obj.data.materials.append(body_material)

    # The face, a hair above the body so the two do not fight for the same
    # pixels, and inset far enough to leave a bezel the chamfer can read
    # against.
    bpy.ops.mesh.primitive_cube_add(
        size=1.0,
        # Sitting on the body's top face (0.085) rather than inside it. At
        # +0.078 the glass was buried: 0.004 proud of a body whose 0.032
        # bevel rounds straight over it, so a unit read as black or as bare
        # metal depending on the angle it was seen from.
        location=(location[0], location[1], location[2] + 0.096),
        rotation=rotation,
    )

    face = bpy.context.active_object
    face.name = f'{name}Face'
    # Thick enough to have a top. At 0.006 with a 0.008 bevel the modifier
    # consumed the whole slab and left a lens - which is why one unit kept
    # coming back with a bright sweep across it while the others stayed dark:
    # a curved screen catches lights a flat one cannot see.
    face.scale = (1.17, 0.77, 0.022)

    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)

    face_bevel = face.modifiers.new('Bevel', 'BEVEL')
    face_bevel.width = 0.004
    face_bevel.segments = 3
    face_bevel.limit_method = 'ANGLE'

    face.data.materials.append(glass_material)

    return obj


def aim(obj: bpy.types.Object, target: bpy.types.Object) -> None:
    """Point a light or a camera at the subject, rather than at an angle
    somebody typed and then nudged."""
    constraint = obj.constraints.new('TRACK_TO')
    constraint.target = target
    constraint.track_axis = 'TRACK_NEGATIVE_Z'
    constraint.up_axis = 'UP_Y'


def black_card(target: bpy.types.Object) -> None:
    """The flag above the subject, which is why a screen photographs dark.

    A flat face does not reflect what is above it into this camera - it
    reflects what is **behind and above** it, at the mirror of the camera's
    own angle. The first card was hung directly overhead and changed nothing,
    because the thing the face was showing was the rim light standing behind
    the subject. This one leans into that path.
    """
    bpy.ops.mesh.primitive_plane_add(size=26.0, location=(0.0, 5.2, 3.4))

    card = bpy.context.active_object
    card.name = 'BlackCard'
    card.rotation_euler = (math.radians(58), 0.0, 0.0)

    material = surface('Card', (0.004, 0.004, 0.005, 1.0), 0.0, 1.0)
    card.data.materials.append(material)

    # It is a reflection, not a shape: it must not cast a shadow of its own
    # or appear in the frame.
    card.visible_camera = False
    card.visible_shadow = False

    del target


def studio(target: bpy.types.Object) -> None:
    """Key, fill and rim - the three lights a product sits in.

    The rim is what draws the bright edge along the chamfer, and it is the
    one that makes a render read as a photograph rather than as a 3D model.
    """
    black_card(target)

    for name, location, size, energy in (
        # All three under the card, and the key from the side rather than
        # overhead: light that reaches the body without landing on the face.
        # In front and above, which is the one place a light can be without
        # the faces showing it to the camera: a horizontal mirror sends the
        # camera's own ray up and *behind*, so anything level with the
        # subject or beyond it comes back as a white sheet. The key sat at
        # y=-0.6 and the nearest unit mirrored it perfectly.
        ('Key', (-5.0, -4.2, 4.0), 4.0, 1200.0),
        ('Fill', (4.2, -2.2, 1.4), 4.0, 240.0),
        # Low and to the side, grazing the body's chamfer rather than facing
        # the screen: a rim light standing behind a flat face is a rim light
        # the face shows the camera.
        ('Rim', (-2.6, 3.4, 0.9), 2.0, 700.0),
    ):
        light_data = bpy.data.lights.new(name, 'AREA')
        light_data.energy = energy
        light_data.size = size

        light = bpy.data.objects.new(name, light_data)
        bpy.context.collection.objects.link(light)
        light.location = location

        aim(light, target)


def camera(target: bpy.types.Object, location) -> bpy.types.Object:
    data = bpy.data.cameras.new('Camera')
    # 85mm: a product lens. A wide angle bends the slab's edges and reads as
    # a phone snap rather than as a studio shot.
    data.lens = 85.0

    obj = bpy.data.objects.new('Camera', data)
    bpy.context.collection.objects.link(obj)
    obj.location = location

    aim(obj, target)

    bpy.context.scene.camera = obj

    return obj


def dark_world() -> None:
    """Near-black, because every polished surface in the frame reflects it.

    The default grey is a soft box the size of the sky, and a product render
    lit by one is a product render with no blacks in it.
    """
    world = bpy.context.scene.world

    if world is None:
        world = bpy.data.worlds.new('World')
        bpy.context.scene.world = world

    world.use_nodes = True
    background = world.node_tree.nodes.get('Background')

    if background is not None:
        background.inputs['Color'].default_value = (0.006, 0.006, 0.008, 1.0)
        background.inputs['Strength'].default_value = 1.0


def configure(width: int, height: int, samples: int) -> None:
    dark_world()

    scene = bpy.context.scene

    scene.render.engine = 'CYCLES'
    scene.cycles.use_denoising = True

    # Transparent, because the tile it rests on is the page's colour and the
    # shadow under it is CSS - DESIGN.md's one shadow, which belongs to the
    # document rather than to the render.
    scene.render.film_transparent = True

    scene.render.resolution_x = width
    scene.render.resolution_y = height

    # Two knobs for iterating on the composition without waiting for a final
    # render: a percentage and a sample count. Nothing else changes, so what
    # comes back is the same picture, smaller and noisier.
    scene.render.resolution_percentage = int(os.environ.get('RENDER_SCALE', '100'))
    scene.cycles.samples = int(os.environ.get('RENDER_SAMPLES', str(samples)))
    scene.render.image_settings.file_format = 'PNG'
    scene.render.image_settings.color_mode = 'RGBA'

    try:
        prefs = bpy.context.preferences.addons['cycles'].preferences
        prefs.get_devices()

        for kind in ('OPTIX', 'CUDA', 'HIP', 'METAL', 'ONEAPI'):
            prefs.compute_device_type = kind

            if any(device.type == kind for device in prefs.devices):
                for device in prefs.devices:
                    device.use = device.type in (kind, 'CPU')

                scene.cycles.device = 'GPU'
                break
    except Exception:
        # No GPU configured. Cycles falls back to the CPU, which is slower
        # and identical.
        pass


def render_to(path: str) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)

    bpy.context.scene.render.filepath = path
    bpy.ops.render.render(write_still=True)

    print(f'wrote {path}')


def build_hero() -> None:
    """One device, three-quarter, studio-lit.

    It was a fan of three, and three meant one was always turned into the key
    light - a flat glass face at a grazing angle returns it as a white sheet,
    so one unit in every frame read as bare metal while the others read
    correctly. The reference's own hero is a single product; the fan was this
    script inventing a requirement and then fighting it.
    """
    clear_scene()

    skins = materials()

    subject = slab('Hero', (0.0, 0.0, 0.0), (0.0, 0.0, math.radians(-7)), skins)

    studio(subject)
    # Close enough that the slab fills about four fifths of the frame. It
    # stood at 5.3 units and filled two fifths, so the shop was serving a
    # 2000px picture that was mostly transparency - the page sized the *box*
    # to `52rem` and the object inside it came out half that, with the empty
    # margin reading as a gap nobody could find in the stylesheet.
    camera(subject, (0.0, -3.58, 2.10))

    # 2000x900, not 2000x1200. A slab seen from above is a wide, shallow
    # shape, and a taller frame is transparency above and below it - which
    # the page then reserves space for, because the document is told the
    # file's real size. The frame is cropped to the composition rather than
    # the composition floated in a frame.
    configure(2000, 900, 160)
    render_to(os.path.join(OUT, 'hero.png'))


def build_product(name: str, tiers: int) -> None:
    """One, two or three slabs stacked: the tier, read without a caption.

    Almost flat-lay, where the hero is three-quarter, and that is arithmetic
    rather than taste. The card crops to a **square** so a row of plans is a
    row of equal tiles; a slab photographed at the hero's angle is a 2.3:1
    shape, and a 2.3:1 shape centred in a square is a card that is half empty
    above and below the product. Seen from overhead and turned on the spot it
    is roughly 1.1:1, which is the square it has to live in.
    """
    clear_scene()

    skins = materials()

    subject = None

    for index in range(tiers):
        obj = slab(
            f'Tier{index}',
            (0.0, 0.0, index * 0.42),
            (0.0, 0.0, math.radians(34 - index * 9)),
            skins,
        )

        if index == 0:
            subject = obj

    assert subject is not None

    studio(subject)
    # Overhead, and further back as the stack grows so a tall plan is framed
    # like a short one rather than cropped.
    camera(subject, (0.0, -1.47, 4.99 + (tiers - 1) * 0.42))

    configure(800, 800, 160)
    render_to(os.path.join(OUT, 'products', f'{name}.png'))


# The products this installation sells, and how many units each plan reads as.
#
# Keyed by slug, because `StorefrontImagery::product()` looks a file up by slug
# and has **no fallback** - so a `default.png` would be a render nothing asks
# for, which is the same mistake as a setting nothing reads. A plan with no
# entry here simply has no picture, and the tile is composed for that.
PRODUCTS: dict[str, int] = {
    'baslangic-paketi': 1,
}


if __name__ == '__main__':
    build_hero()

    for slug, tiers in PRODUCTS.items():
        build_product(slug, tiers)

    print('done', file=sys.stderr)
